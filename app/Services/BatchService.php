<?php
namespace App\Services;

use App\Models\Business;
use App\Models\BatchEmail;
use App\Models\Category;
use App\Models\City;
use App\Models\Company;
use App\Models\EmailBatch;
use App\Models\UnsubscribedEmail;
use Illuminate\Support\Facades\DB;

class BatchService
{
    public function isPremium(Company $company): bool
    {
        return $company->plan === 'premium'
            && $company->plan_expires_at !== null
            && $company->plan_expires_at->isFuture();
    }

    public function resetDailySendsIfNeeded(Company $company): void
    {
        $today = now()->startOfDay();
        if ($company->daily_sends_reset_at === null || $company->daily_sends_reset_at->lt($today)) {
            $company->update(['daily_sends_used' => 0, 'daily_sends_reset_at' => now()]);
        }
    }

    private function baseQuery(Company $company)
    {
        $query = Business::query()
            ->whereNotNull('email')
            ->where('email', 'like', '%@%');

        $sources = $company->getAllowedSources();
        if (!empty($sources)) {
            $query->whereIn('source', $sources);
        }

        $unsubEmails = UnsubscribedEmail::pluck('email');
        if ($unsubEmails->isNotEmpty()) {
            $query->whereNotIn('email', $unsubEmails);
        }

        return $query;
    }

    private function alreadyPurchasedSubquery(Company $company)
    {
        return BatchEmail::select('business_id')
            ->join('email_batches', 'batch_emails.batch_id', '=', 'email_batches.id')
            ->where('email_batches.company_id', $company->id);
    }

    public function purchaseBatch(Company $company, ?string $categoryName, ?string $cityName, int $batchSize): array
    {
        $cat = null;
        $cityObj = null;
        $labelParts = [];

        if ($categoryName) {
            $cat = Category::where('name', $categoryName)->first();
            if (!$cat) abort(404, 'Category not found');
            $labelParts[] = $cat->name;
        }

        if ($cityName) {
            $cityObj = City::whereRaw('LOWER(name) = LOWER(?)', [trim($cityName)])->first();
            if (!$cityObj) abort(400, "City \"{$cityName}\" not found. Check the spelling and try again.");
            $labelParts[] = $cityObj->name;
        }

        $query = $this->baseQuery($company)
            ->whereNotIn('id', $this->alreadyPurchasedSubquery($company));

        if ($cat) $query->where('category_id', $cat->id);
        if ($cityObj) $query->where('city_id', $cityObj->id);

        $available = $query->take($batchSize)->get();
        if ($available->isEmpty()) abort(400, 'No available emails for this selection');

        $actualSize = $available->count();
        $actualCost = 0.0;
        $label = implode(' — ', $labelParts) ?: 'Custom batch';

        return DB::transaction(function () use ($company, $cat, $cityObj, $label, $actualSize, $actualCost, $available) {
            $batch = EmailBatch::create([
                'company_id' => $company->id,
                'category_id' => $cat?->id,
                'city_id' => $cityObj?->id,
                'label' => $label,
                'batch_size' => $actualSize,
                'price_paid' => $actualCost,
                'purchased_at' => now(),
            ]);

            foreach ($available as $biz) {
                BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $biz->id]);
            }

            $company->refresh();
            return [
                'batch_id' => $batch->id,
                'batch_size' => $actualSize,
                'cost' => $actualCost,
                'remaining_credits' => $company->credit_balance,
            ];
        });
    }

    public function countBatchMulti(Company $company, array $categoryNames, ?string $cityName): array
    {
        if (empty($categoryNames)) return ['count' => 0, 'city_found' => true];

        $cats = Category::whereIn('name', $categoryNames)->get();
        if ($cats->isEmpty()) return ['count' => 0, 'city_found' => true];

        $cityObj = null;
        if ($cityName) {
            $cityObj = City::whereRaw('LOWER(name) = LOWER(?)', [trim($cityName)])->first();
            if (!$cityObj) return ['count' => 0, 'city_found' => false];
        }

        $query = $this->baseQuery($company)
            ->whereNotIn('id', $this->alreadyPurchasedSubquery($company))
            ->whereIn('category_id', $cats->pluck('id'));

        if ($cityObj) $query->where('city_id', $cityObj->id);

        return ['count' => $query->count(), 'city_found' => true];
    }

    public function purchaseBatchMulti(Company $company, array $categoryNames, ?string $cityName): array
    {
        if (empty($categoryNames)) abort(400, 'Select at least one category');

        $cats = Category::whereIn('name', $categoryNames)->get();
        $found = $cats->pluck('name')->toArray();
        $missing = array_diff($categoryNames, $found);
        if (!empty($missing)) abort(404, 'Categories not found: ' . implode(', ', $missing));

        $cityObj = null;
        if ($cityName) {
            $cityObj = City::whereRaw('LOWER(name) = LOWER(?)', [trim($cityName)])->first();
            if (!$cityObj) abort(400, "City \"{$cityName}\" not found. Check the spelling and try again.");
        }

        $query = $this->baseQuery($company)
            ->whereNotIn('id', $this->alreadyPurchasedSubquery($company))
            ->whereIn('category_id', $cats->pluck('id'));

        if ($cityObj) $query->where('city_id', $cityObj->id);

        $available = $query->get();
        if ($available->isEmpty()) abort(400, 'No available emails for this selection');

        $actualSize = $available->count();
        $actualCost = 0.0;

        $label = implode(' — ', $categoryNames);
        if ($cityObj) $label .= " ({$cityObj->name})";
        if (strlen($label) > 500) $label = substr($label, 0, 497) . '...';

        return DB::transaction(function () use ($company, $cityObj, $label, $actualSize, $actualCost, $available) {
            $batch = EmailBatch::create([
                'company_id' => $company->id,
                'category_id' => null,
                'city_id' => $cityObj?->id,
                'label' => $label,
                'batch_size' => $actualSize,
                'price_paid' => $actualCost,
                'purchased_at' => now(),
            ]);

            foreach ($available as $biz) {
                BatchEmail::create(['batch_id' => $batch->id, 'business_id' => $biz->id]);
            }

            $company->refresh();
            return [
                'batch_id' => $batch->id,
                'batch_size' => $actualSize,
                'cost' => $actualCost,
                'remaining_credits' => $company->credit_balance,
            ];
        });
    }
}
