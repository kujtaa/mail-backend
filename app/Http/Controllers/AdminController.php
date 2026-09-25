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
use App\Services\UnsubscribeService;
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
        $search = trim((string)$request->query('search', ''));
        $source = $request->query('source', 'all');
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 20)));

        $query = UnsubscribedEmail::orderByDesc('unsubscribed_at')->orderByDesc('id')
            ->when($search !== '', fn($q) => $q->where('email', 'like', '%' . strtolower($search) . '%'))
            ->when(in_array($source, [UnsubscribedEmail::SOURCE_LINK, UnsubscribedEmail::SOURCE_MANUAL], true),
                fn($q) => $q->where('source', $source));

        $total = (clone $query)->count();
        $rows = $query->skip(($page - 1) * $perPage)->take($perPage)->get();

        $bizIds = $rows->pluck('business_id')->filter();
        $bizMap = Business::whereIn('businesses.id', $bizIds)
            ->leftJoin('cities', 'businesses.city_id', '=', 'cities.id')
            ->leftJoin('categories', 'businesses.category_id', '=', 'categories.id')
            ->select('businesses.id', 'businesses.name', 'cities.name as city_name', 'categories.name as cat_name')
            ->get()->keyBy('id');

        $adderMap = Company::whereIn('id', $rows->pluck('added_by')->filter())->pluck('name', 'id');

        return response()->json([
            'total' => $total,
            'counts' => [
                'all' => UnsubscribedEmail::count(),
                'link' => UnsubscribedEmail::where('source', UnsubscribedEmail::SOURCE_LINK)->count(),
                'manual' => UnsubscribedEmail::where('source', UnsubscribedEmail::SOURCE_MANUAL)->count(),
            ],
            'items' => $rows->map(function ($r) use ($bizMap, $adderMap) {
                $biz = $bizMap->get($r->business_id);
                return [
                    'id' => $r->id,
                    'email' => $r->email,
                    'business_name' => $biz?->name,
                    'city' => $biz?->city_name,
                    'category' => $biz?->cat_name,
                    'source' => $r->source,
                    'added_by' => $r->added_by ? $adderMap->get($r->added_by) : null,
                    'note' => $r->note,
                    'unsubscribed_at' => $r->unsubscribed_at?->toISOString(),
                ];
            }),
        ]);
    }

    /**
     * Manually add one or more addresses to the suppression list.
     * Accepts {"emails": ["a@x.com", "b@y.com"], "note": "..."} or a single {"email": "a@x.com"}.
     */
    public function addUnsubscribed(Request $request, UnsubscribeService $unsubscribeService)
    {
        $data = $request->validate([
            'email' => 'required_without:emails|string|max:255',
            'emails' => 'required_without:email|array|min:1|max:1000',
            'emails.*' => 'string|max:255',
            'note' => 'nullable|string|max:500',
        ]);

        $candidates = collect($data['emails'] ?? [$data['email']])
            ->map(fn($e) => UnsubscribedEmail::normalize($e))
            ->filter()
            ->unique()
            ->values();

        $added = [];
        $existing = [];
        $invalid = [];

        foreach ($candidates as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $email;
                continue;
            }
            [, $created] = $unsubscribeService->suppress(
                $email, UnsubscribedEmail::SOURCE_MANUAL, $request->user(), $data['note'] ?? null,
            );
            $created ? $added[] = $email : $existing[] = $email;
        }

        $parts = [];
        if ($added) $parts[] = count($added) . ' added';
        if ($existing) $parts[] = count($existing) . ' already unsubscribed';
        if ($invalid) $parts[] = count($invalid) . ' invalid';

        return response()->json([
            'detail' => $parts ? implode(', ', $parts) : 'Nothing to add',
            'added' => $added,
            'existing' => $existing,
            'invalid' => $invalid,
        ], $added || $existing ? 200 : 422);
    }
}
