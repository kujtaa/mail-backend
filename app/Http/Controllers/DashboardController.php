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
use App\Jobs\SendQueuedEmail;
use App\Services\BatchService;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    private const BATCH_SEND_DELAY_SECONDS = 5;

    public function __construct(
        private EmailService $emailService,
        private BatchService $batchService,
    ) {}

    private function ensureEmailQueueStorageIsReady(): void
    {
        if (config('queue.default') !== 'database') {
            return;
        }

        $table = config('queue.connections.database.table', 'jobs');
        $connection = config('queue.connections.database.connection');
        $schema = $connection ? Schema::connection($connection) : Schema::getFacadeRoot();

        if (!$schema->hasTable($table)) {
            abort(response()->json([
                'detail' => 'Email queue storage is not ready. Run database migrations before sending emails.',
                'message' => 'Email queue storage is not ready. Run database migrations before sending emails.',
            ], 503));
        }
    }

    private function applySourceFilter($query, Company $company)
    {
        $sources = $company->getAllowedSources();
        if (!empty($sources)) {
            $query->whereIn('source', $sources);
        }
        return $query;
    }

    public function stats(Request $request)
    {
        $company = $request->user();
        $this->batchService->resetDailySendsIfNeeded($company);
        $company->refresh();

        $base = $this->applySourceFilter(Business::query(), $company);

        $totalEmails = (clone $base)->whereNotNull('email')->where('email', 'like', '%@%')->count();
        $totalBiz = (clone $base)->count();
        $withWeb = (clone $base)->whereNotNull('website')->where('website', '!=', '')->count();
        $totalCats = (clone $base)->distinct()->count('category_id');
        $totalCities = (clone $base)->distinct()->count('city_id');

        $purchased = BatchEmail::join('email_batches', 'batch_emails.batch_id', '=', 'email_batches.id')
            ->where('email_batches.company_id', $company->id)->count();
        $batches = EmailBatch::where('company_id', $company->id)->count();
        $sent = SentEmail::where('company_id', $company->id)->where('status', 'sent')->count();
        $failed = SentEmail::where('company_id', $company->id)->where('status', 'failed')->count();

        return response()->json([
            'total_emails_available' => $totalEmails,
            'total_businesses' => $totalBiz,
            'total_with_website' => $withWeb,
            'total_without_website' => $totalBiz - $withWeb,
            'total_categories' => $totalCats,
            'total_cities' => $totalCities,
            'emails_purchased' => $purchased,
            'emails_sent' => $sent,
            'emails_failed' => $failed,
            'credit_balance' => $company->credit_balance,
            'batches_count' => $batches,
            'smtp_configured' => (bool)($company->smtp_enabled && $company->smtp_host),
            'plan' => 'full_access',
            'daily_send_limit' => 0,
            'daily_sends_remaining' => null,
        ]);
    }

    public function breakdown(Request $request)
    {
        $company = $request->user();
        $sources = $company->getAllowedSources();

        $catQ = DB::table('businesses')
            ->join('categories', 'businesses.category_id', '=', 'categories.id')
            ->select('categories.name', DB::raw('COUNT(businesses.id) as total'),
                DB::raw('COUNT(CASE WHEN businesses.email LIKE \'%@%\' THEN 1 END) as with_email'))
            ->when(!empty($sources), fn($q) => $q->whereIn('businesses.source', $sources))
            ->groupBy('categories.name')->orderByDesc('total')->get();

        $cityQ = DB::table('businesses')
            ->join('cities', 'businesses.city_id', '=', 'cities.id')
            ->select('cities.name', DB::raw('COUNT(businesses.id) as total'),
                DB::raw('COUNT(CASE WHEN businesses.email LIKE \'%@%\' THEN 1 END) as with_email'))
            ->when(!empty($sources), fn($q) => $q->whereIn('businesses.source', $sources))
            ->groupBy('cities.name')->orderByDesc('total')->get();

        return response()->json([
            'categories' => $catQ->map(fn($r) => ['name' => $r->name, 'total' => $r->total, 'with_email' => $r->with_email]),
            'cities' => $cityQ->map(fn($r) => ['name' => $r->name, 'total' => $r->total, 'with_email' => $r->with_email]),
        ]);
    }

    public function categories(Request $request)
    {
        $company = $request->user();
        $alreadyPurchased = BatchEmail::select('business_id')
            ->join('email_batches', 'batch_emails.batch_id', '=', 'email_batches.id')
            ->where('email_batches.company_id', $company->id);

        $unsub = UnsubscribedEmail::pluck('email');

        $query = Category::select('categories.id', 'categories.name', DB::raw('COUNT(DISTINCT businesses.id) as available_count'))
            ->join('businesses', 'businesses.category_id', '=', 'categories.id')
            ->whereNotNull('businesses.email')->where('businesses.email', 'like', '%@%')
            ->whereNotIn('businesses.id', $alreadyPurchased)
            ->when($unsub->isNotEmpty(), fn($q) => $q->whereNotIn('businesses.email', $unsub));

        $sources = $company->getAllowedSources();
        if (!empty($sources)) $query->whereIn('businesses.source', $sources);

        $results = $query->groupBy('categories.id', 'categories.name')->orderBy('categories.name')->get();

        return response()->json($results->map(fn($r) => [
            'category_id' => $r->id,
            'category_name' => $r->name,
            'available_count' => $r->available_count,
        ]));
    }

    public function browseOverview(Request $request)
    {
        $company = $request->user();
        $alreadyPurchased = BatchEmail::select('business_id')
            ->join('email_batches', 'batch_emails.batch_id', '=', 'email_batches.id')
            ->where('email_batches.company_id', $company->id);

        $sources = $company->getAllowedSources();
        $unsub = UnsubscribedEmail::pluck('email');

        $base = Business::whereNotNull('email')->where('email', 'like', '%@%')
            ->whereNotIn('businesses.id', $alreadyPurchased)
            ->when($unsub->isNotEmpty(), fn($q) => $q->whereNotIn('email', $unsub))
            ->when(!empty($sources), fn($q) => $q->whereIn('source', $sources));

        $total = (clone $base)->count();

        $byCategory = (clone $base)
            ->join('categories', 'businesses.category_id', '=', 'categories.id')
            ->select('categories.name', DB::raw('COUNT(DISTINCT businesses.id) as count'))
            ->groupBy('categories.name')->orderByDesc('count')->get()
            ->map(fn($r) => ['name' => $r->name, 'count' => $r->count]);

        $byCity = (clone $base)
            ->join('cities', 'businesses.city_id', '=', 'cities.id')
            ->select('cities.name', DB::raw('COUNT(DISTINCT businesses.id) as count'))
            ->groupBy('cities.name')->orderByDesc('count')->get()
            ->map(fn($r) => ['name' => $r->name, 'count' => $r->count]);

        return response()->json(['total_available' => $total, 'by_category' => $byCategory, 'by_city' => $byCity]);
    }

    public function categoriesList(Request $request)
    {
        $company = $request->user();
        $alreadyPurchased = BatchEmail::select('business_id')
            ->join('email_batches', 'batch_emails.batch_id', '=', 'email_batches.id')
            ->where('email_batches.company_id', $company->id);

        $sources = $company->getAllowedSources();
        $unsub = UnsubscribedEmail::pluck('email');

        $results = Category::select('categories.name', DB::raw('COUNT(DISTINCT businesses.id) as count'))
            ->join('businesses', 'businesses.category_id', '=', 'categories.id')
            ->whereNotNull('businesses.email')->where('businesses.email', 'like', '%@%')
            ->whereNotIn('businesses.id', $alreadyPurchased)
            ->when($unsub->isNotEmpty(), fn($q) => $q->whereNotIn('businesses.email', $unsub))
            ->when(!empty($sources), fn($q) => $q->whereIn('businesses.source', $sources))
            ->groupBy('categories.name')->orderBy('categories.name')->get();

        return response()->json($results->map(fn($r) => ['name' => $r->name, 'count' => $r->count]));
    }

    public function browseEmails(Request $request)
    {
        $company = $request->user();
        $category = $request->query('category', 'all');
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 20)));

        $sources = $company->getAllowedSources();
        $unsub = UnsubscribedEmail::pluck('email');

        $query = Business::select('businesses.id', 'businesses.name', 'businesses.email',
                'cities.name as city_name', 'categories.name as cat_name')
            ->join('cities', 'businesses.city_id', '=', 'cities.id')
            ->join('categories', 'businesses.category_id', '=', 'categories.id')
            ->whereNotNull('businesses.email')->where('businesses.email', 'like', '%@%')
            ->when($unsub->isNotEmpty(), fn($q) => $q->whereNotIn('businesses.email', $unsub))
            ->when(!empty($sources), fn($q) => $q->whereIn('businesses.source', $sources))
            ->when($category !== 'all', fn($q) => $q->where('categories.name', $category))
            ->skip(($page - 1) * $perPage)->take($perPage);

        return response()->json($query->get()->map(fn($b) => [
            'id' => $b->id,
            'business_name' => $b->name,
            'email' => $b->email,
            'city' => $b->city_name,
            'category' => $b->cat_name,
        ]));
    }

    public function purchaseBatch(Request $request)
    {
        $data = $request->validate([
            'category' => 'nullable|string',
            'city' => 'nullable|string',
            'batch_size' => 'required|integer|min:1',
        ]);

        if (empty($data['category']) && empty($data['city'])) {
            abort(400, 'Specify at least a category or a city');
        }

        $result = $this->batchService->purchaseBatch(
            $request->user(), $data['category'] ?? null, $data['city'] ?? null, $data['batch_size']
        );
        return response()->json($result);
    }

    public function estimateBatchMulti(Request $request)
    {
        $data = $request->validate([
            'categories' => 'required|array|min:1',
            'categories.*' => 'string',
            'city' => 'nullable|string',
        ]);

        $result = $this->batchService->countBatchMulti(
            $request->user(), $data['categories'], $data['city'] ?? null
        );
        return response()->json($result);
    }

    public function purchaseBatchMulti(Request $request)
    {
        $data = $request->validate([
            'categories' => 'required|array|min:1',
            'categories.*' => 'string',
            'city' => 'nullable|string',
        ]);

        $result = $this->batchService->purchaseBatchMulti(
            $request->user(), $data['categories'], $data['city'] ?? null
        );
        return response()->json($result);
    }

    public function myBatches(Request $request)
    {
        $company = $request->user();
        $batches = EmailBatch::where('company_id', $company->id)
            ->with(['category', 'city'])
            ->orderByDesc('purchased_at')
            ->get();

        return response()->json($batches->map(fn($b) => [
            'id' => $b->id,
            'label' => $b->label,
            'category_name' => $b->category?->name,
            'city_name' => $b->city?->name,
            'batch_size' => $b->batch_size,
            'price_paid' => $b->price_paid,
            'purchased_at' => $b->purchased_at?->toISOString(),
        ]));
    }

    public function batchEmails(int $batchId, Request $request)
    {
        $company = $request->user();
        $batch = EmailBatch::where('id', $batchId)->where('company_id', $company->id)->first();
        if (!$batch) abort(404, 'Batch not found');

        $results = BatchEmail::where('batch_id', $batchId)
            ->join('businesses', 'batch_emails.business_id', '=', 'businesses.id')
            ->join('cities', 'businesses.city_id', '=', 'cities.id')
            ->join('categories', 'businesses.category_id', '=', 'categories.id')
            ->whereNotExists(function ($query) use ($company) {
                $query->select(DB::raw(1))
                    ->from('sent_emails')
                    ->whereColumn('sent_emails.batch_email_id', 'batch_emails.id')
                    ->where('sent_emails.company_id', $company->id)
                    ->where('sent_emails.status', 'sent');
            })
            ->select('batch_emails.id', 'businesses.name', 'businesses.email',
                'cities.name as city_name', 'categories.name as cat_name')
            ->get();

        return response()->json($results->map(fn($r) => [
            'id' => $r->id,
            'business_name' => $r->name,
            'email' => $r->email,
            'city' => $r->city_name,
            'category' => $r->cat_name,
        ]));
    }

    public function sendEmail(Request $request)
    {
        $company = $request->user();
        $data = $request->validate([
            'batch_email_ids' => 'required|array|min:1',
            'batch_email_ids.*' => 'integer',
            'subject' => 'required|string',
            'body' => 'required|string',
        ]);

        if (!$company->smtp_host || !$company->smtp_user || !$company->smtp_pass) {
            abort(400, 'SMTP not configured. Go to Settings to set up your email.');
        }
        if (!$company->smtp_enabled) {
            abort(400, 'SMTP is disabled. Enable it in Settings before sending.');
        }
        $this->ensureEmailQueueStorageIsReady();

        $validIds = BatchEmail::join('email_batches', 'batch_emails.batch_id', '=', 'email_batches.id')
            ->where('email_batches.company_id', $company->id)
            ->whereIn('batch_emails.id', $data['batch_email_ids'])
            ->whereNotExists(function ($query) use ($company) {
                $query->select(DB::raw(1))
                    ->from('sent_emails')
                    ->whereColumn('sent_emails.batch_email_id', 'batch_emails.id')
                    ->where('sent_emails.company_id', $company->id)
                    ->where('sent_emails.status', 'sent');
            })
            ->pluck('batch_emails.id')->toArray();

        if (empty($validIds)) abort(400, 'No unsent batch emails found');

        $records = [];
        foreach ($validIds as $id) {
            $records[] = SentEmail::updateOrCreate([
                'company_id' => $company->id,
                'batch_email_id' => $id,
            ], [
                'subject' => $data['subject'],
                'body' => $data['body'],
                'status' => 'pending',
                'sent_at' => null,
                'error_message' => null,
            ]);
        }

        foreach ($records as $index => $record) {
            SendQueuedEmail::dispatch($record->id)
                ->delay(now()->addSeconds($index * self::BATCH_SEND_DELAY_SECONDS));
        }

        return response()->json([
            'queued' => count($records),
            'delay_seconds' => self::BATCH_SEND_DELAY_SECONDS,
        ]);
    }

    public function sendManual(Request $request)
    {
        $company = $request->user();
        $data = $request->validate([
            'emails' => 'required|array|min:1|max:20',
            'emails.*' => 'string',
            'subject' => 'required|string',
            'body' => 'required|string',
        ]);

        if (!$company->smtp_host || !$company->smtp_user || !$company->smtp_pass) {
            abort(400, 'SMTP not configured. Go to Settings to set up your email.');
        }
        if (!$company->smtp_enabled) {
            abort(400, 'SMTP is disabled. Enable it in Settings before sending.');
        }

        $results = [];
        foreach ($data['emails'] as $to) {
            $to = trim($to);
            if (!$to || !str_contains($to, '@')) {
                $results[] = ['email' => $to, 'status' => 'failed', 'error' => 'Invalid email'];
                continue;
            }
            $unsubUrl = $this->emailService->buildUnsubscribeUrl($to);
            [$success, $error] = $this->emailService->sendSingle(
                $company->smtp_host, $company->smtp_port ?? 587,
                $company->smtp_user, $company->smtp_pass,
                $company->smtp_from_email ?? $company->smtp_user,
                $company->smtp_from_name ?? $company->name,
                $to, $data['subject'], $data['body'],
                $unsubUrl, $company->email_signature,
            );
            $results[] = ['email' => $to, 'status' => $success ? 'sent' : 'failed', 'error' => $error];
        }

        return response()->json([
            'sent' => collect($results)->where('status', 'sent')->count(),
            'total' => count($results),
            'results' => $results,
        ]);
    }

    public function sentHistory(Request $request)
    {
        $company = $request->user();
        $page = max(1, (int)$request->query('page', 1));
        $perPage = min(100, max(1, (int)$request->query('per_page', 20)));
        $status = $request->query('status');

        $results = SentEmail::where('sent_emails.company_id', $company->id)
            ->join('batch_emails', 'sent_emails.batch_email_id', '=', 'batch_emails.id')
            ->join('businesses', 'batch_emails.business_id', '=', 'businesses.id')
            ->select('sent_emails.*', 'businesses.email as recipient_email')
            ->when($status, fn($q) => $q->where('sent_emails.status', $status))
            ->orderByDesc('sent_emails.id')
            ->skip(($page - 1) * $perPage)->take($perPage)
            ->get();

        return response()->json($results->map(fn($r) => [
            'id' => $r->id,
            'recipient_email' => $r->recipient_email,
            'subject' => $r->subject,
            'sent_at' => $r->sent_at?->toISOString(),
            'status' => $r->status,
            'error_message' => $r->error_message,
        ]));
    }

    public function processNextPendingSentEmail(Request $request)
    {
        $company = $request->user();
        $record = SentEmail::where('company_id', $company->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->first();

        if (!$record) {
            return response()->json([
                'processed' => false,
                'remaining_pending' => 0,
            ]);
        }

        app()->call([new SendQueuedEmail($record->id), 'handle']);
        $record->refresh();
        $remainingPending = SentEmail::where('company_id', $company->id)
            ->where('status', 'pending')
            ->count();

        return response()->json([
            'processed' => true,
            'id' => $record->id,
            'status' => $record->status,
            'error_message' => $record->error_message,
            'remaining_pending' => $remainingPending,
        ]);
    }

    public function retrySentEmails(Request $request)
    {
        $company = $request->user();
        $data = $request->validate([
            'sent_email_ids' => 'required|array|min:1|max:150',
            'sent_email_ids.*' => 'integer',
        ]);
        $this->ensureEmailQueueStorageIsReady();

        $records = SentEmail::where('company_id', $company->id)
            ->whereIn('id', $data['sent_email_ids'])
            ->whereIn('status', ['failed', 'pending'])
            ->orderBy('id')
            ->get();

        foreach ($records as $index => $record) {
            $record->update([
                'status' => 'pending',
                'sent_at' => null,
                'error_message' => null,
            ]);

            SendQueuedEmail::dispatch($record->id)
                ->delay(now()->addSeconds($index * self::BATCH_SEND_DELAY_SECONDS));
        }

        return response()->json([
            'queued' => $records->count(),
            'delay_seconds' => self::BATCH_SEND_DELAY_SECONDS,
        ]);
    }

    public function sentHistoryProgress(Request $request)
    {
        $company = $request->user();
        $data = $request->validate([
            'sent_email_ids' => 'required|array|min:1|max:150',
            'sent_email_ids.*' => 'integer',
        ]);

        $counts = SentEmail::where('company_id', $company->id)
            ->whereIn('id', $data['sent_email_ids'])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $sent = (int)($counts['sent'] ?? 0);
        $failed = (int)($counts['failed'] ?? 0);
        $pending = (int)($counts['pending'] ?? 0);
        $total = (int)$counts->sum();

        return response()->json([
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'pending' => $pending,
            'completed' => $total > 0 && $pending === 0,
        ]);
    }

    public function getSmtpSettings(Request $request)
    {
        $c = $request->user();
        return response()->json([
            'smtp_host' => $c->smtp_host,
            'smtp_port' => $c->smtp_port,
            'smtp_user' => $c->smtp_user,
            'smtp_from_email' => $c->smtp_from_email,
            'smtp_from_name' => $c->smtp_from_name,
            'smtp_enabled' => (bool)$c->smtp_enabled,
            'has_password' => (bool)$c->smtp_pass,
            'email_signature' => $c->email_signature,
        ]);
    }

    public function saveSmtpSettings(Request $request)
    {
        $data = $request->validate([
            'smtp_host' => 'required|string|max:255',
            'smtp_port' => 'required|integer',
            'smtp_user' => 'required|string|max:255',
            'smtp_pass' => 'nullable|string',
            'smtp_from_email' => 'required|email|max:255',
            'smtp_from_name' => 'nullable|string|max:255',
            'smtp_enabled' => 'boolean',
        ]);

        $company = $request->user();
        $update = [
            'smtp_host' => $data['smtp_host'],
            'smtp_port' => $data['smtp_port'],
            'smtp_user' => $data['smtp_user'],
            'smtp_from_email' => $data['smtp_from_email'],
            'smtp_from_name' => $data['smtp_from_name'] ?? $company->name,
            'smtp_enabled' => $data['smtp_enabled'] ?? true,
        ];
        if (!empty($data['smtp_pass'])) {
            $update['smtp_pass'] = $data['smtp_pass'];
        }

        $company->update($update);
        return $this->getSmtpSettings($request);
    }

    public function saveSignature(Request $request)
    {
        $data = $request->validate([
            'email_signature' => 'nullable|string',
        ]);
        $request->user()->update([
            'email_signature' => $data['email_signature'] ?? null,
        ]);
        return response()->json(['ok' => true]);
    }

    public function testSmtp(Request $request)
    {
        $company = $request->user();
        $data = $request->validate(['to_email' => 'required|email']);

        if (!$company->smtp_host || !$company->smtp_user || !$company->smtp_pass) {
            abort(400, 'SMTP settings not configured. Save your settings first.');
        }
        if (!$company->smtp_enabled) {
            abort(400, 'SMTP is disabled. Enable the toggle and save before testing.');
        }

        [$ok, $error] = $this->emailService->sendTestEmail(
            $company->smtp_host, $company->smtp_port ?? 587,
            $company->smtp_user, $company->smtp_pass,
            $company->smtp_from_email ?? $company->smtp_user,
            $company->smtp_from_name ?? $company->name,
            $data['to_email'],
        );

        if ($ok) return response()->json(['detail' => "Test email sent successfully to {$data['to_email']}"]);
        abort(400, "SMTP test failed: {$error}");
    }
}
