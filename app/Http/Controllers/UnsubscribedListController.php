<?php
namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Company;
use App\Models\UnsubscribedEmail;
use App\Services\UnsubscribeService;
use Illuminate\Http\Request;

/**
 * Suppression list, visible to every approved company. The list is global:
 * an address unsubscribed once is never emailed by anyone again.
 */
class UnsubscribedListController extends Controller
{
    public function index(Request $request)
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
     * Any approved company may do this; the row records who added it.
     * Accepts {"emails": ["a@x.com", "b@y.com"], "note": "..."} or a single {"email": "a@x.com"}.
     */
    public function store(Request $request, UnsubscribeService $unsubscribeService)
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
            if (!UnsubscribedEmail::isValidAddress($email)) {
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
        if ($invalid) $parts[] = count($invalid) . ' invalid: ' . implode(', ', $invalid);

        return response()->json([
            'detail' => $parts ? implode(', ', $parts) : 'Nothing to add',
            'added' => $added,
            'existing' => $existing,
            'invalid' => $invalid,
        ], $added || $existing ? 200 : 422);
    }
}
