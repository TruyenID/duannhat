<?php

namespace App\Services\Promotion;

use App\Exceptions\MenuPromotionException;
use App\Models\MenuPromotion;
use App\Omnify\Enums\MenuPromotionAppliesToEnum;
use App\Omnify\Enums\MenuPromotionStackingModeEnum;
use App\Services\Order\Contracts\PromotionRedemptionReads;
use App\Services\Promotion\Contracts\ActiveMenuPromotion;
use App\Services\Promotion\Contracts\MenuPromotionMutationFacade;
use App\Services\Promotion\Contracts\MenuPromotionResolver;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Plan-019 — MenuPromotionService.
 *
 * Owns shop-scoped time-of-day + weekday + scope filtered % discount
 * promotions. Auto-applied at CustomerOrderService::addItems (Decision
 * B2 — wired in T2.6); per-promotion stacking_mode controls whether
 * the resulting item line still allows a coupon (Decision B5).
 *
 * Key decisions:
 *  - B1: shop-scoped (branch_id NOT NULL).
 *  - B3: when multiple promotions match the same product, apply the
 *        highest discount_percent. No additive stacking.
 *  - B4: scope is two pivots (category + product) instead of polymorphic.
 *  - B6: snapshot the discounted price into CustomerOrderItem.unit_price
 *        and the original into original_unit_price.
 *
 * Cache:
 *  - Key: `branch:{branch_id}:active_promotions:{YYYY-MM-DD}`
 *  - TTL: 60 seconds.
 *  - Disabled when config('promotion.cache_enabled') === false (default
 *    false in tests; true in production).
 *  - Invalidated on every CRUD/toggle for the affected branch.
 */
class MenuPromotionService implements MenuPromotionMutationFacade, MenuPromotionResolver
{
    protected function applyListEagerLoads($query): void
    {
        $query->with(['translations', 'categories:id,name', 'products:id,name']);
    }

    protected function applyFindByIdEagerLoads($query): void
    {
        $query->with(['translations', 'categories:id,name', 'products:id,name'])
            ->withCount(['customerOrderItems as items_with_promotion_count']);
    }

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{
     *     organization_id?: string,
     *     brand_id?: string,
     *     branch_id?: string,
     *     applies_to?: string,
     *     stacking_mode?: string,
     *     is_active?: bool,
     *     currently_active?: bool,
     *     search?: string,
     *     sort?: string,
     *     per_page?: int,
     *     with_trashed?: bool,
     * }  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = MenuPromotion::query()
            ->with([
                'translations',
                'categories:id,name',
                'products:id,name',
                // Plan-019 — eager-load branch so HQ resource exposes
                // branch_slug + branch_name flat fields without N+1.
                'branch',
            ]);

        // Plan-019 — `with_report=true` (HQ list) adds two aggregates that
        // power the cross-shop KPI tiles + per-row totals. Subqueries are
        // O(1) per row but only worth running when the caller actually
        // renders the columns; shop scope list skips them.
        if (! empty($filters['with_report'])) {
            $query->withCount(['customerOrderItems as items_with_promotion_count'])
                ->withSum(
                    ['customerOrderItems as total_discount_amount' => function ($q) {
                        $q->whereNotNull('original_unit_price');
                    }],
                    \DB::raw('(original_unit_price - unit_price) * quantity'),
                );
        }

        $query->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id));
        $query->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id));
        $query->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id));
        $query->when($filters['applies_to'] ?? null, fn ($q, $v) => $q->where('applies_to', $v));
        $query->when($filters['stacking_mode'] ?? null, fn ($q, $v) => $q->where('stacking_mode', $v));
        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));

        if (! empty($filters['currently_active'])) {
            $now = CarbonImmutable::now();
            $query->where('is_active', true)
                ->where('valid_from', '<=', $now)
                ->where('valid_until', '>=', $now);
        }

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->whereTranslationLike('name', "%{$search}%");
        });

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $allowed = ['created_at', 'updated_at', 'discount_percent', 'valid_from', 'valid_until'];
        $column = in_array($column, $allowed, true) ? $column : 'created_at';
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    // =========================================================================
    //  Mutations
    // =========================================================================

    /**
     * Load one promotion with the relations the resource renders. The show()
     * endpoint (MenuPromotionController) has always called $service->findById(),
     * but this service extends no CRUD base, so the method never existed and
     * every GET of a single promotion 500'd with "Call to undefined method".
     * Eager-loads mirror list() so the resource resolves without N+1.
     */
    public function findById(string $id): MenuPromotion
    {
        return MenuPromotion::query()
            ->with(['translations', 'categories:id,name', 'products:id,name', 'branch'])
            ->findOrFail($id);
    }

    public function create(array $data): MenuPromotion
    {
        return $this->storePromotion($data);
    }

    public function storePromotion(array $data): MenuPromotion
    {
        $categoryIds = $data['applicable_category_ids'] ?? [];
        $productIds = $data['applicable_product_ids'] ?? [];
        unset($data['applicable_category_ids'], $data['applicable_product_ids']);

        $promotion = $this->persistPromotionCreate($data);

        $this->syncPivots($promotion, $categoryIds, $productIds);
        $this->invalidateBranchCache($promotion->branch_id);
        $promotion->logAudit('promotion_created');

        return $promotion->fresh(['translations', 'categories', 'products']);
    }

    public function update(MenuPromotion $promotion, array $data): MenuPromotion
    {
        return $this->revisePromotion($promotion, $data);
    }

    public function revisePromotion(MenuPromotion $promotion, array $data): MenuPromotion
    {
        $itemsWithPromotion = $promotion->customerOrderItems()->count();
        $this->assertNotLocked($promotion, $data, $itemsWithPromotion);

        $categoryIds = array_key_exists('applicable_category_ids', $data)
            ? ($data['applicable_category_ids'] ?? [])
            : null;
        $productIds = array_key_exists('applicable_product_ids', $data)
            ? ($data['applicable_product_ids'] ?? [])
            : null;
        unset($data['applicable_category_ids'], $data['applicable_product_ids']);

        $updated = $this->persistPromotionUpdate($promotion, $data);

        if ($categoryIds !== null || $productIds !== null) {
            $this->syncPivots($updated, $categoryIds ?? [], $productIds ?? []);
        }
        $this->invalidateBranchCache($updated->branch_id);
        $updated->logAudit('promotion_updated');

        return $updated->fresh(['translations', 'categories', 'products']);
    }

    public function delete(MenuPromotion $promotion): bool
    {
        return $this->archivePromotion($promotion);
    }

    public function archivePromotion(MenuPromotion $promotion): bool
    {
        $itemCount = $promotion->customerOrderItems()->count();
        if ($itemCount > 0) {
            throw MenuPromotionException::alreadyUsedUseDeactivateInstead($itemCount);
        }

        return DB::transaction(function () use ($promotion) {
            $result = $promotion->delete();
            $this->invalidateBranchCache($promotion->branch_id);
            $promotion->logAudit('promotion_deleted');

            return $result;
        });
    }

    public function restore(MenuPromotion $promotion): MenuPromotion
    {
        return $this->unarchivePromotion($promotion);
    }

    public function unarchivePromotion(MenuPromotion $promotion): MenuPromotion
    {
        $restored = $this->persistPromotionRestore($promotion);
        $this->invalidateBranchCache($restored->branch_id);
        $restored->logAudit('promotion_restored');

        return $restored->fresh(['translations', 'categories', 'products']);
    }

    public function toggle(MenuPromotion $promotion): MenuPromotion
    {
        return DB::transaction(function () use ($promotion) {
            $promotion->update(['is_active' => ! $promotion->is_active]);
            $this->invalidateBranchCache($promotion->branch_id);
            $promotion->logAudit('promotion_toggled', ['is_active' => $promotion->is_active]);

            return $promotion->fresh(['translations', 'categories', 'products']);
        });
    }

    // =========================================================================
    //  Resolution (auto-apply hot path)
    // =========================================================================

    /**
     * Find the SINGLE promotion (highest discount_percent wins, B3) that
     * matches the (branch, product, [categories], at) tuple right now.
     * Returns null if none match.
     */
    public function activeFor(
        string $branchId,
        string $productId,
        array $categoryIds = [],
        ?DateTimeInterface $at = null,
    ): ?ActiveMenuPromotion {
        $promotion = $this->resolveActivePromotion($branchId, $productId, $categoryIds, $at);

        if ($promotion === null) {
            return null;
        }

        return new ActiveMenuPromotion(
            id: (string) $promotion->id,
            discountPercent: (float) $promotion->discount_percent,
            stackingMode: $promotion->stacking_mode instanceof MenuPromotionStackingModeEnum
                ? $promotion->stacking_mode
                : MenuPromotionStackingModeEnum::tryFrom((string) $promotion->stacking_mode),
        );
    }

    /**
     * @return array{name: array<string, string>, discount_percent: string, stacking_mode: string}|null
     */
    public function snapshotFor(?string $promotionId): ?array
    {
        if ($promotionId === null) {
            return null;
        }

        $promotion = MenuPromotion::query()->find($promotionId);
        if ($promotion === null) {
            return null;
        }

        return [
            'name' => collect($promotion->getTranslationsArray())
                ->map(fn (array $translation): ?string => $translation['name'] ?? null)
                ->filter(fn (?string $name): bool => $name !== null && $name !== '')
                ->all(),
            'discount_percent' => (string) $promotion->discount_percent,
            'stacking_mode' => $promotion->stacking_mode,
        ];
    }

    public function resolveActivePromotion(
        string $branchId,
        string $productId,
        array $categoryIds = [],
        ?DateTimeInterface $at = null,
    ): ?MenuPromotion {
        $candidates = $this->candidatesForBranch($branchId, $at);
        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->filter(fn (MenuPromotion $p) => $this->matchesScope($p, $productId, $categoryIds))
            ->sortByDesc(fn (MenuPromotion $p) => (float) $p->discount_percent)
            ->sortBy(fn (MenuPromotion $p) => $p->created_at?->getTimestamp() ?? 0)
            ->sortByDesc(fn (MenuPromotion $p) => (float) $p->discount_percent)
            ->first();
    }

    /**
     * Batch resolve for the customer-menu API. Given a list of items
     * (each {product_id, category_ids[]}), returns a map
     * `[product_id => MenuPromotion|null]`.
     *
     * @param  array<int, array{product_id: string, category_ids: array<int, string>}>  $items
     * @return array<string, MenuPromotion|null>
     */
    public function resolveActivePromotionsForMenu(string $branchId, array $items, ?DateTimeInterface $at = null): array
    {
        $candidates = $this->candidatesForBranch($branchId, $at);
        if ($candidates->isEmpty()) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            $matched = $candidates
                ->filter(fn (MenuPromotion $p) => $this->matchesScope($p, $item['product_id'], $item['category_ids'] ?? []))
                ->sortBy(fn (MenuPromotion $p) => $p->created_at?->getTimestamp() ?? 0)
                ->sortByDesc(fn (MenuPromotion $p) => (float) $p->discount_percent)
                ->first();
            $out[$item['product_id']] = $matched;
        }

        return $out;
    }

    /**
     * Aggregate report for the detail page: how many order items applied
     * this promotion, total discount they got, first/last redemption.
     *
     * @return array{
     *     items_with_promotion_count: int,
     *     total_discount_applied: float,
     *     first_redeemed_at: ?string,
     *     last_redeemed_at: ?string,
     * }
     */
    public function report(MenuPromotion $promotion): array
    {
        return $this->redemptions()->summaryForPromotion((string) $promotion->id);
    }

    /**
     * Recent CustomerOrderItem rows that applied this promotion, newest
     * first. Used by Shop promotion detail "Báo cáo" tab (plan-019 B.S9).
     *
     * @return array<int, array{
     *     id: string,
     *     customer_order_id: string,
     *     order_code: ?string,
     *     product_name: ?string,
     *     original_unit_price: ?float,
     *     unit_price: float,
     *     applied_at: string,
     * }>
     */
    public function recentItems(MenuPromotion $promotion, int $limit = 50): array
    {
        return $this->redemptions()->recentForPromotion((string) $promotion->id, $limit);
    }

    /**
     * #962 — cổng đọc dòng-món-đã-áp-khuyến-mãi do Ordering công bố.
     *
     * Resolve lười trong method thay vì constructor-inject: `MenuPromotionService`
     * được dựng bằng `new` ở vài chỗ (resource, facade binding), nên thêm tham
     * số constructor bắt buộc sẽ làm lộ ra những call site đó.
     */
    private function redemptions(): PromotionRedemptionReads
    {
        return app(PromotionRedemptionReads::class);
    }

    // =========================================================================
    //  Internals
    // =========================================================================

    /**
     * Cached fetch: every active, in-window promotion for the branch at
     * `at`. Per Decision Q6 cache: 60s TTL, key per branch+date so
     * invalidation on CRUD only blasts the relevant branch.
     */
    protected function candidatesForBranch(string $branchId, ?DateTimeInterface $at = null): Collection
    {
        $now = $at ? CarbonImmutable::instance($at) : CarbonImmutable::now();

        $loader = function () use ($branchId, $now) {
            $models = MenuPromotion::query()
                ->where('branch_id', $branchId)
                ->where('is_active', true)
                ->where('valid_from', '<=', $now)
                ->where('valid_until', '>=', $now)
                ->with(['categories:id', 'products:id'])
                ->get();

            // Filter daily window + weekday in PHP — windowing on the DB
            // would need a DATE_FORMAT trick that isn't worth the cost
            // for small per-branch result sets.
            $branchTz = $this->resolveBranchTimezone($branchId);
            $localNow = $now->setTimezone($branchTz);
            $localTime = $localNow->format('H:i:s');
            $isoWeekday = (int) $localNow->isoWeekday();

            return $models->filter(fn (MenuPromotion $p) => $this->matchesDailyWindow($p, $localTime, $isoWeekday));
        };

        if (! config('promotion.cache_enabled', false)) {
            return $loader();
        }

        $key = "branch:{$branchId}:active_promotions:".$now->toDateString();
        $cached = Cache::remember($key, now()->addSeconds(60), function () use ($loader) {
            return $loader()->pluck('id')->all();
        });

        // The cache stores IDs only (avoid serializing the full Eloquent
        // graph + its relations). Re-hydrate fresh from the DB so callers
        // always get an unstale model.
        if ($cached === []) {
            return collect();
        }

        return MenuPromotion::query()
            ->whereIn('id', $cached)
            ->with(['categories:id', 'products:id'])
            ->get();
    }

    protected function resolveBranchTimezone(string $branchId): string
    {
        // #1091 — single source of truth for business time.
        return BusinessClock::timezoneForBranch($branchId);
    }

    /**
     * Decision Q6: evaluate the daily window at the BRANCH timezone (a
     * 21:00–23:00 Vietnam happy hour matches when local clocks tick over,
     * not when UTC does).
     */
    protected function matchesDailyWindow(MenuPromotion $promotion, string $localTime, int $isoWeekday): bool
    {
        // Weekdays
        $weekdays = $promotion->weekdays;
        if (is_array($weekdays) && $weekdays !== []) {
            $values = array_map('intval', $weekdays);
            if (! in_array($isoWeekday, $values, true)) {
                return false;
            }
        }

        $from = $promotion->daily_time_from;
        $to = $promotion->daily_time_to;
        if ($from === null && $to === null) {
            return true;
        }
        if ($from === null || $to === null) {
            // Schema invariant: from/to are both-null or both-non-null.
            // Treat malformed pair as "no time restriction" rather than
            // failing the resolver.
            return true;
        }

        $fromCmp = $this->normalizeTime($from);
        $toCmp = $this->normalizeTime($to);
        $nowCmp = $localTime;

        if ($fromCmp <= $toCmp) {
            // Normal window: from ≤ now ≤ to
            return $nowCmp >= $fromCmp && $nowCmp <= $toCmp;
        }

        // Cross-midnight: window is from..23:59 OR 00:00..to
        return $nowCmp >= $fromCmp || $nowCmp <= $toCmp;
    }

    protected function normalizeTime(string $t): string
    {
        // MySQL TIME columns persist as HH:MM:SS. Pad bare HH:MM to be safe.
        return strlen($t) === 5 ? $t.':00' : $t;
    }

    protected function matchesScope(MenuPromotion $promotion, string $productId, array $categoryIds): bool
    {
        $applies = $this->appliesToValue($promotion);
        if ($applies === MenuPromotionAppliesToEnum::AllItems->value) {
            return true;
        }

        $promotionCategoryIds = $promotion->categories->pluck('id')->all();
        $promotionProductIds = $promotion->products->pluck('id')->all();

        return match ($applies) {
            MenuPromotionAppliesToEnum::Categories->value => array_intersect($categoryIds, $promotionCategoryIds) !== [],
            MenuPromotionAppliesToEnum::Products->value => in_array($productId, $promotionProductIds, true),
            MenuPromotionAppliesToEnum::Mixed->value => array_intersect($categoryIds, $promotionCategoryIds) !== []
                || in_array($productId, $promotionProductIds, true),
            default => false,
        };
    }

    /**
     * MenuPromotion::applies_to comes back as a MenuPromotionAppliesToEnum
     * when hydrated via Eloquent (cast fires) or as a raw string when
     * constructed via `new MenuPromotion([...])` for tests.
     */
    private function appliesToValue(MenuPromotion $promotion): string
    {
        $value = $promotion->applies_to;

        return $value instanceof MenuPromotionAppliesToEnum ? $value->value : (string) $value;
    }

    /**
     * @param  array<int, string>  $categoryIds
     * @param  array<int, string>  $productIds
     */
    protected function syncPivots(MenuPromotion $promotion, array $categoryIds, array $productIds): void
    {
        // `applies_to` is cast to MenuPromotionAppliesToEnum, so normalise to
        // its string value before the strict in_array — comparing an enum
        // instance against string `.value`s with strict mode is always false,
        // which previously left every category/product pivot empty.
        $applies = $promotion->applies_to instanceof MenuPromotionAppliesToEnum
            ? $promotion->applies_to->value
            : $promotion->applies_to;
        $useCategories = in_array($applies, [MenuPromotionAppliesToEnum::Categories->value, MenuPromotionAppliesToEnum::Mixed->value], true);
        $useProducts = in_array($applies, [MenuPromotionAppliesToEnum::Products->value, MenuPromotionAppliesToEnum::Mixed->value], true);

        $promotion->categories()->sync($useCategories ? $categoryIds : []);
        $promotion->products()->sync($useProducts ? $productIds : []);
    }

    /**
     * BR-MP-LOCK — once items have applied the promotion, the contract-
     * shaping fields (discount_percent + applies_to + scope pivots) are
     * frozen. Other fields (name/description/valid_until-extend/weekdays/
     * daily_time/is_active/stacking_mode) remain editable.
     */
    protected function assertNotLocked(MenuPromotion $promotion, array $data, int $itemsWithPromotion): void
    {
        if ($itemsWithPromotion === 0) {
            return;
        }

        if (array_key_exists('discount_percent', $data) && (string) $data['discount_percent'] !== (string) $promotion->discount_percent) {
            throw MenuPromotionException::lockedField('discount_percent', $itemsWithPromotion);
        }

        if (array_key_exists('applies_to', $data) && $data['applies_to'] !== $promotion->applies_to) {
            throw MenuPromotionException::lockedField('applies_to', $itemsWithPromotion);
        }

        if (array_key_exists('applicable_category_ids', $data) || array_key_exists('applicable_product_ids', $data)) {
            // Allow pivot edits ONLY if the values are unchanged.
            $currentCats = $promotion->categories()->pluck('categories.id')->sort()->values()->all();
            $currentProds = $promotion->products()->pluck('products.id')->sort()->values()->all();
            $newCats = collect($data['applicable_category_ids'] ?? $currentCats)->sort()->values()->all();
            $newProds = collect($data['applicable_product_ids'] ?? $currentProds)->sort()->values()->all();
            if ($newCats !== $currentCats) {
                throw MenuPromotionException::lockedField('applicable_category_ids', $itemsWithPromotion);
            }
            if ($newProds !== $currentProds) {
                throw MenuPromotionException::lockedField('applicable_product_ids', $itemsWithPromotion);
            }
        }
    }

    protected function invalidateBranchCache(string $branchId): void
    {
        if (! config('promotion.cache_enabled', false)) {
            return;
        }
        // Blast both today and tomorrow's keys to cover requests in flight
        // straddling local midnight.
        $today = CarbonImmutable::today();
        Cache::forget("branch:{$branchId}:active_promotions:".$today->toDateString());
        Cache::forget("branch:{$branchId}:active_promotions:".$today->addDay()->toDateString());
    }

    protected function persistPromotionCreate(array $data): MenuPromotion
    {
        return DB::transaction(function () use ($data) {
            unset($data['created_by_id']);
            if (Auth::check()) {
                $data['created_by_id'] = Auth::id();
            }

            $data['stacking_mode'] = $data['stacking_mode'] ?? 'stackable_with_coupons';
            $data['is_active'] = $data['is_active'] ?? true;

            $model = MenuPromotion::create($data);
            $this->flushTranslations($model);

            return $model;
        });
    }

    protected function persistPromotionUpdate(MenuPromotion $model, array $data): MenuPromotion
    {
        return DB::transaction(function () use ($model, $data) {
            unset($data['updated_by_id']);
            if (Auth::check()) {
                $data['updated_by_id'] = Auth::id();
            }

            $model->update($data);
            $this->flushTranslations($model);

            return $model;
        });
    }

    protected function persistPromotionRestore(MenuPromotion $model): MenuPromotion
    {
        return DB::transaction(function () use ($model) {
            $model->restore();

            return $model;
        });
    }

    protected function flushTranslations(MenuPromotion $model): void
    {
        foreach ($model->translations as $translation) {
            if (! $translation->exists || $translation->isDirty()) {
                if (! empty($connectionName = $model->getConnectionName())) {
                    $translation->setConnection($connectionName);
                }
                $translation->setAttribute(
                    $model->getTranslationRelationKey(),
                    $model->getKey()
                );
                $translation->save();
            }
        }
    }
}
