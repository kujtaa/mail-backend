<?php
namespace App\Http\Controllers;

use App\Models\BatchEmail;
use App\Models\Business;
use App\Models\Category;
use App\Models\City;
use App\Models\Company;
use App\Models\CreditTransaction;
use App\Models\EmailBatch;
use App\Models\SentEmail;
use App\Models\UnsubscribedEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function listCompanies()
    {
        $companies = Company::orderByDesc('created_at')->get();

        return response()->json($companies->map(function ($c) {
            $batchesCount = EmailBatch::where('company_id', $c->id)->count();
            $totalEmails = BatchEmail::join('email_batches', 'batch_emails.batch_id', '=', 'email_batches.id')
                ->where('email_batches.company_id', $c->id)->count();

            return [
                'id' => $c->id, 'name' => $c->name, 'email' => $c->email,
                'credit_balance' => $c->credit_balance, 'is_admin' => $c->is_admin,
                'is_approved' => $c->is_approved, 'plan' => $c->plan,
                'plan_expires_at' => $c->plan_expires_at?->toISOString(),
                'daily_send_limit' => $c->daily_send_limit,
                'allowed_sources' => $c->getAllowedSources(),
                'batches_count' => $batchesCount,
                'total_purchased_emails' => $totalEmails,
                'created_at' => $c->created_at?->toISOString(),
            ];
        }));
    }

    public function addCredits(Request $request)
    {
        $data = $request->validate([
            'company_id' => 'required|integer',
            'amount' => 'required|numeric|gt:0',
            'description' => 'nullable|string',
        ]);

        $company = Company::findOrFail($data['company_id']);
        $company->increment('credit_balance', $data['amount']);
        CreditTransaction::create([
            'company_id' => $company->id,
            'amount' => $data['amount'],
            'type' => 'topup',
            'description' => $data['description'] ?? "Admin credit topup: {$data['amount']}",
            'created_at' => now(),
        ]);

        return response()->json(['company_id' => $company->id, 'new_balance' => (float) $company->fresh()->credit_balance], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    public function approveCompany(int $companyId)
    {
        $company = Company::findOrFail($companyId);
        $company->update(['is_approved' => true]);
        return response()->json(['detail' => "Company '{$company->name}' approved"]);
    }

    public function rejectCompany(int $companyId)
    {
        $company = Company::findOrFail($companyId);
        if ($company->is_admin) abort(400, 'Cannot reject admin account');
        $company->update(['is_approved' => false]);
        return response()->json(['detail' => "Company '{$company->name}' approval revoked"]);
    }

    public function allEmails(Request $request)
    {
        $category = $request->query('category', 'all');
        $city = $request->query('city', 'all');
        $search = $request->query('search', '');
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 20)));

        $query = Business::select('businesses.id', 'businesses.name', 'businesses.email',
                'cities.name as city_name', 'categories.name as cat_name')
            ->join('cities', 'businesses.city_id', '=', 'cities.id')
            ->join('categories', 'businesses.category_id', '=', 'categories.id')
            ->whereNotNull('businesses.email')->where('businesses.email', 'like', '%@%')
            ->when($category !== 'all', fn($q) => $q->where('categories.name', $category))
            ->when($city !== 'all', fn($q) => $q->where('cities.name', $city))
            ->when($search, fn($q) => $q->where(function ($q2) use ($search) {
                $q2->where('businesses.name', 'like', "%{$search}%")
                   ->orWhere('businesses.email', 'like', "%{$search}%");
            }));

        $total = (clone $query)->count();
        $emails = $query->orderBy('businesses.name')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'total' => $total,
            'emails' => $emails->map(fn($r) => [
                'id' => $r->id, 'business_name' => $r->name, 'email' => $r->email,
                'city' => $r->city_name, 'category' => $r->cat_name,
            ]),
        ]);
    }

    public function filterOptions()
    {
        $cities = City::join('businesses', 'businesses.city_id', '=', 'cities.id')
            ->distinct()->orderBy('cities.name')->pluck('cities.name');
        $cats = Category::join('businesses', 'businesses.category_id', '=', 'categories.id')
            ->distinct()->orderBy('categories.name')->pluck('categories.name');

        return response()->json(['cities' => $cities, 'categories' => $cats]);
    }

    public function noWebsiteBusinesses(Request $request)
    {
        $city = $request->query('city', 'all');
        $category = $request->query('category', 'all');
        $search = $request->query('search', '');
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 20)));

        $query = Business::select('businesses.id', 'businesses.name', 'businesses.phone',
                'businesses.email', 'businesses.address', 'businesses.website',
                'cities.name as city_name', 'categories.name as cat_name')
            ->join('cities', 'businesses.city_id', '=', 'cities.id')
            ->join('categories', 'businesses.category_id', '=', 'categories.id')
            ->where(function ($q) {
                $q->whereNull('businesses.website')
                  ->orWhere('businesses.website', '')
                  ->orWhere('businesses.website', 'https://www.search.ch/index.en.html');
            })
            ->when($city !== 'all', fn($q) => $q->where('cities.name', $city))
            ->when($category !== 'all', fn($q) => $q->where('categories.name', $category))
            ->when($search, fn($q) => $q->where(function ($q2) use ($search) {
                $q2->where('businesses.name', 'like', "%{$search}%")
                   ->orWhere('businesses.email', 'like', "%{$search}%")
                   ->orWhere('businesses.phone', 'like', "%{$search}%");
            }));

        $total = (clone $query)->count();
        $rows = $query->orderBy('businesses.name')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'total' => $total,
            'businesses' => $rows->map(fn($r) => [
                'id' => $r->id, 'name' => $r->name, 'phone' => $r->phone,
                'email' => $r->email, 'address' => $r->address, 'website' => $r->website,
                'city' => $r->city_name, 'category' => $r->cat_name,
            ]),
        ]);
    }

    public function transactions(Request $request)
    {
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 50)));

        $query = CreditTransaction::join('companies', 'credit_transactions.company_id', '=', 'companies.id')
            ->select('credit_transactions.*', 'companies.name as company_name')
            ->orderByDesc('credit_transactions.created_at');

        $total = (clone $query)->count();
        $results = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        return response()->json([
            'total' => $total,
            'items' => $results->map(fn($r) => [
                'id' => $r->id, 'company_id' => $r->company_id, 'company_name' => $r->company_name,
                'amount' => $r->amount, 'type' => $r->type, 'description' => $r->description,
                'created_at' => $r->created_at?->toISOString(),
            ]),
        ]);
    }

    public function setPlan(Request $request)
    {
        $data = $request->validate([
            'company_id' => 'required|integer',
            'plan' => 'required|string',
            'daily_limit' => 'nullable|integer',
            'days' => 'nullable|integer',
        ]);

        $company = Company::findOrFail($data['company_id']);
        $update = ['plan' => $data['plan'], 'daily_sends_used' => 0];

        if ($data['plan'] === 'premium') {
            $update['plan_expires_at'] = now()->addDays($data['days'] ?? 30);
            $update['daily_send_limit'] = $data['daily_limit'] ?? 200;
        } else {
            $update['plan_expires_at'] = null;
            $update['daily_send_limit'] = 0;
        }

        $company->update($update);
        return response()->json([
            'detail' => "Plan set to '{$data['plan']}' for {$company->name}",
            'expires_at' => $company->fresh()->plan_expires_at?->toISOString(),
        ]);
    }

    public function deleteCompany(int $companyId)
    {
        $company = Company::findOrFail($companyId);
        if ($company->is_admin) abort(400, 'Cannot delete admin account');

        DB::transaction(function () use ($company) {
            $batchIds = EmailBatch::where('company_id', $company->id)->pluck('id');
            if ($batchIds->isNotEmpty()) {
                $beIds = BatchEmail::whereIn('batch_id', $batchIds)->pluck('id');
                if ($beIds->isNotEmpty()) {
                    SentEmail::whereIn('batch_email_id', $beIds)->delete();
                    BatchEmail::whereIn('id', $beIds)->delete();
                }
                EmailBatch::whereIn('id', $batchIds)->delete();
            }
            CreditTransaction::where('company_id', $company->id)->delete();
            $company->tokens()->delete();
            $company->delete();
        });

        return response()->json(['detail' => "Company '{$company->name}' permanently deleted"]);
    }

    public function setSources(Request $request)
    {
        $data = $request->validate([
            'company_id' => 'required|integer',
            'sources' => 'required|array',
            'sources.*' => 'string|in:local.ch,gelbeseiten.de,herold.at,proff.no,proff.dk',
        ]);

        $company = Company::findOrFail($data['company_id']);
        $company->setAllowedSources($data['sources']);
        $company->save();

        return response()->json([
            'detail' => "Sources updated for '{$company->name}'",
            'sources' => $company->getAllowedSources(),
        ]);
    }

    public function listUnsubscribed(Request $request)
    {
        $search = $request->query('search', '');
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 20)));

        $query = UnsubscribedEmail::orderByDesc('unsubscribed_at')
            ->when($search, fn($q) => $q->where('email', 'like', "%{$search}%"));

        $total = (clone $query)->count();
        $rows = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $bizIds = $rows->pluck('business_id')->filter();
        $bizMap = Business::whereIn('id', $bizIds)
            ->join('cities', 'businesses.city_id', '=', 'cities.id')
            ->join('categories', 'businesses.category_id', '=', 'categories.id')
            ->select('businesses.id', 'businesses.name', 'cities.name as city_name', 'categories.name as cat_name')
            ->get()->keyBy('id');

        return response()->json([
            'total' => $total,
            'items' => $rows->map(function ($r) use ($bizMap) {
                $biz = $bizMap->get($r->business_id);
                return [
                    'id' => $r->id, 'email' => $r->email,
                    'business_name' => $biz?->name, 'city' => $biz?->city_name,
                    'category' => $biz?->cat_name,
                    'unsubscribed_at' => $r->unsubscribed_at?->toISOString(),
                ];
            }),
        ]);
    }

    public function removeUnsubscribed(int $unsubId)
    {
        $unsub = UnsubscribedEmail::findOrFail($unsubId);
        $email = $unsub->email;
        $unsub->delete();
        return response()->json(['detail' => "'{$email}' removed from unsubscribe list"]);
    }
}
