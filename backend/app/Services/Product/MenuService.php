<?php

namespace App\Services\Product;

use App\Exceptions\InvalidStatusTransitionException;
use App\Exceptions\MenuOperationException;
use App\Models\Menu;
use App\Models\MenuAvailabilityEvent;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSchedule;
use App\Models\MenuSection;
use App\Models\ProductSku;
use App\Omnify\Enums\MenuAvailabilityEntityTypeEnum;
use App\Omnify\Enums\MenuStatusEnum;
use App\Omnify\Enums\ProductStatusEnum;
use App\Services\Catalog\MenuSectionPivotWriter;
use App\Services\Product\Contracts\ProductCatalogProjectionPort;
use App\Services\Product\ValueObjects\CatalogSkuProjection;
use App\Services\Product\ValueObjects\MenuAvailabilityActor;
use App\Services\Tax\Contracts\TaxTypeDirectory;
use App\Support\BusinessClock;
use App\Support\MenuScheduleDateRule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class MenuService implements ProductCatalogProjectionPort
{
    /**
     * plan-056 — how far back a client-supplied `occurred_at` is believed.
     *
     * Seven days, matched to how long a shop can plausibly run disconnected
     * and still have its queue drain rather than dead-letter. Anything older
     * is a broken clock, not a late sync.
     */
    private const AVAILABILITY_EVENT_MAX_BACKDATE_DAYS = 7;

    /**
     * #962 — loại thuế thuộc Pricing. Menu chỉ GÁN nhãn thuế lên một tier
     * (#1218 tier 1/2/3); nó không được tự truy vấn `App\Models\TaxType`, và
     * cổng cố ý không trả về mức thuế.
     */
    public function __construct(
        private readonly TaxTypeDirectory $taxTypes,
        // #1661 — mọi lối ghi `menu_menu_sections` đi qua đây; bảng khoá kép nên
        // không observer nào bắt được nó (xem docblock của writer).
        private readonly MenuSectionPivotWriter $sectionPivot,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * withCount/loadCount spec for a menu's "số sản phẩm".
     *
     * menu_products_count counts DISTINCT products, not menu_product rows: a
     * product placed in several sections (e.g. featured in おすすめ AND its home
     * category) is ONE dish, so the number shown in the list + detail is the
     * count of dishes, never the count of section placements. Every list/detail
     * response goes through this so the two views can never disagree.
     *
     * @return array<int|string, mixed>
     */
    public function menuProductCounts(): array
    {
        return [
            'menuProducts as menu_products_count' => fn ($q) => $q->select(DB::raw('count(distinct product_id)')),
            'clonedMenus',
        ];
    }

    /**
     * @param  array{organization_id?: string, brand_id?: string, branch_id?: string, status?: string, is_master?: bool, search?: string, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = Menu::query()
            ->withCount($this->menuProductCounts());

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        $query->when($filters['branch_id'] ?? null, fn ($q, $branchId) => $q->where('branch_id', $branchId));
        $query->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        $query->when(isset($filters['is_master']), fn ($q) => $q->where('is_master', $filters['is_master']));

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): Menu
    {
        return Menu::with([
            'menuSections' => fn ($q) => $q->orderByPivot('display_order'),
            // #3170 — the unique-id tie-break that the paginated listing got
            // in #3160, so the POS detail read agrees with the customer menu
            // and the workstation replica on display_order-tied rows.
            'menuProducts' => fn ($q) => $q->orderBy('display_order')
                ->orderBy('menu_products.id'),
            'menuProducts.product.skus',
            'menuProducts.menuSection',
            'masterMenu',
            'masterMenu.brand',
            'brand',
            'branch',
            'activeSchedules:id,menu_id,start_time,end_time,days_of_week,is_active,priority',
        ])->withCount([
            // DISTINCT products (see menuProductCounts()) so the HQ detail header
            // reads the same dish count as the HQ list — never the row count.
            'menuProducts as menu_products_count' => fn ($q) => $q->select(DB::raw('count(distinct product_id)')),
            'clonedMenus',
            'schedules',
        ])->findOrFail($id);
    }

    /**
     * Get the current active menu for a branch (highest priority within valid dates and schedule windows).
     *
     * Schedule resolution:
     *   - Menus with zero non-deleted schedule rows → always-on (backward compat).
     *   - Menus with non-deleted rows must match the current day + time window.
     *   - days_of_week bitmask: bit0=Sun…bit6=Sat, matching PHP Carbon dayOfWeek (0=Sun…6=Sat).
     *   - MySQL DAYOFWEEK()-1 produces the same bit position (1-indexed Sun=1 → bit0=1).
     *
     * Branch schedule overrides are applied via correlated COALESCE subqueries —
     * branch-overridden times take precedence over HQ schedule times in the window check.
     */
    public function getCurrentMenu(string $branchId, string $organizationId, ?string $brandId = null): ?Menu
    {
        // Compute the wall clock in PHP so Carbon::setTestNow() takes effect
        // (MySQL CURRENT_TIME/CURRENT_DATE would evaluate in the DB session's
        // timezone). #1091 — the wall clock is the BRANCH's, same rule as
        // MenuPromotionService: a 07:00–09:00 breakfast window means 07:00 at
        // the shop, whether the shop is in Tokyo or Hanoi. `$now` (the raw
        // instant) still drives the valid_from/valid_to instant comparisons.
        $now = now();
        $local = BusinessClock::now($branchId);
        $dayOfWeek = (int) $local->dayOfWeek;
        $currentTime = $local->format('H:i:s');
        $currentDate = $local->toDateString();

        return Menu::where('organization_id', $organizationId)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->where('branch_id', $branchId)
            ->where('status', MenuStatusEnum::Active->value)
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
            })
            ->where(function ($q) use ($dayOfWeek, $branchId, $currentTime, $currentDate) {
                // Always-on: menu has no non-deleted schedule rows.
                $q->whereDoesntHave('schedules', fn ($s) => $s->whereNull('deleted_at'))
                    // Scheduled: at least one active window matching today + branch-aware time.
                    ->orWhereHas('activeSchedules', function ($s) use ($dayOfWeek, $branchId, $currentTime, $currentDate) {
                        // Which DAYS the row covers — weekday mask, day-of-month
                        // mask or an explicit date list (#1979) — plus the
                        // calendar window (#1970), all branch-aware. One shared
                        // definition; see MenuScheduleDateRule for why it is not
                        // spelled out again here.
                        MenuScheduleDateRule::apply($s, $branchId, $dayOfWeek, $currentDate);

                        // Time of day stays local: it is the only part that is
                        // not about which calendar days the row covers.
                        $s
                            // COALESCE: branch override start_time if set, else HQ default.
                            ->whereRaw(
                                'COALESCE(
                                    (SELECT o.start_time FROM branch_schedule_overrides o
                                     WHERE o.menu_schedule_id = menu_schedules.id AND o.branch_id = ?),
                                    menu_schedules.start_time
                                ) <= ?',
                                [$branchId, $currentTime]
                            )
                            // COALESCE: branch override end_time if set, else HQ default.
                            ->whereRaw(
                                'COALESCE(
                                    (SELECT o.end_time FROM branch_schedule_overrides o
                                     WHERE o.menu_schedule_id = menu_schedules.id AND o.branch_id = ?),
                                    menu_schedules.end_time
                                ) >= ?',
                                [$branchId, $currentTime]
                            );
                    });
            })
            // Lower priority number = higher priority (schema contract + reorder
            // assigns 1-based top-down). Was orderByDesc — picked the wrong menu.
            ->orderBy('priority')
            ->with([
                'menuProducts.menuProductSkus.productSku',
                'activeSchedules:id,menu_id,start_time,end_time,days_of_week,is_active,priority',
            ])
            ->first();
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): Menu
    {
        $data = $this->normalizeTranslations($data);

        return DB::transaction(function () use ($data) {
            if (! isset($data['status'])) {
                $data['status'] = MenuStatusEnum::Draft->value;
            }

            if (! isset($data['is_master'])) {
                $data['is_master'] = false;
            }

            if (! isset($data['priority'])) {
                $data['priority'] = $this->getNextPriority($data['branch_id'] ?? null);
            }

            $productIds = $data['product_ids'] ?? [];
            unset($data['product_ids']);

            $menu = Menu::create($data);
            // Same rule as addProducts() / reconcileMenuProducts: non-master
            // menus need MenuProductSku rows so the shop-side menu detail can
            // list variants. Master menus skip — cloneToBranch reads variants
            // from product.skus, not master.menuProductSkus.
            $shouldCreateSkus = ! $menu->is_master;

            foreach ($productIds as $index => $productId) {
                $menuProduct = $menu->menuProducts()->create([
                    'product_id' => $productId,
                    'is_active' => true,
                    'display_order' => $index + 1,
                ]);

                if ($shouldCreateSkus) {
                    $this->createSkusForMenuProduct($menuProduct);
                }
            }

            return $menu->load(['menuProducts.product.skus', 'masterMenu'])
                ->loadCount($this->menuProductCounts());
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(Menu $menu, array $data): Menu
    {
        $data = $this->normalizeTranslations($data);
        $expectedUpdatedAt = $data['updated_at'] ?? null;
        unset($data['updated_at']);

        return DB::transaction(function () use ($menu, $data, $expectedUpdatedAt) {
            $menu = Menu::query()->lockForUpdate()->findOrFail($menu->getKey());
            if ($expectedUpdatedAt !== null
                && ! Carbon::parse($expectedUpdatedAt)->equalTo($menu->updated_at)) {
                throw new ConflictHttpException('This menu was changed by another user. Reload and try again.');
            }

            if (! in_array($menu->status, [MenuStatusEnum::Draft->value, MenuStatusEnum::Rejected->value], true)) {
                // service_type (#463) is display-routing metadata — safe to flip on
                // any status (like cart_timeout_minutes), so it stays whitelisted for
                // approved/active menus. Without this the change is silently dropped.
                $allowed = [
                    'name', 'description', 'valid_from', 'valid_to', 'priority',
                    'cart_timeout_minutes', 'service_type',
                    'name:ja', 'name:en', 'name:vi',
                    'description:ja', 'description:en', 'description:vi',
                ];
                $data = array_intersect_key($data, array_flip($allowed));
            }

            $menu->update($data);

            return $menu->load(['menuProducts.product.skus', 'masterMenu'])
                ->loadCount($this->menuProductCounts());
        });
    }

    /**
     * Convert the admin wire shape (`ja.name`) to Astrotomic's unambiguous
     * flat keys (`name:ja`). Nested locale arrays are ambiguous when more
     * than one translated field is submitted together.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTranslations(array $data): array
    {
        foreach (['ja', 'en', 'vi'] as $locale) {
            foreach (['name', 'description'] as $field) {
                if (array_key_exists($field, $data[$locale] ?? [])) {
                    $data["{$field}:{$locale}"] = $data[$locale][$field];
                }
            }
            unset($data[$locale]);
        }

        return $data;
    }

    // =========================================================================
    //  Delete & Restore
    // =========================================================================

    public function delete(Menu $menu): bool
    {
        if ($menu->status === MenuStatusEnum::Active->value) {
            throw new MenuOperationException('Cannot delete an active menu.');
        }

        return DB::transaction(function () use ($menu) {
            $menu->menuProducts->each(function (MenuProduct $mp) {
                $mp->menuProductSkus()->delete();
                $mp->delete();
            });

            return $menu->delete();
        });
    }

    public function restore(Menu $menu): Menu
    {
        return DB::transaction(function () use ($menu) {
            $deletedAt = $menu->deleted_at;

            $menu->restore();

            $menuProducts = $menu->menuProducts()->withTrashed()
                ->where('deleted_at', '>=', $deletedAt)
                ->get();

            foreach ($menuProducts as $mp) {
                $mp->restore();
                $mp->menuProductSkus()->withTrashed()
                    ->where('deleted_at', '>=', $deletedAt)
                    ->restore();
            }

            return $menu->load(['menuProducts.product.skus', 'masterMenu'])
                ->loadCount($this->menuProductCounts());
        });
    }

    public function restoreFromTrash(string $menuId, string $organizationId, ?string $brandId = null): Menu
    {
        return Menu::withTrashed()
            ->where('organization_id', $organizationId)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->findOrFail($menuId);
    }

    // =========================================================================
    //  Product Management
    // =========================================================================

    /**
     * Add products to a menu. Non-master menus (whether cloned from a master or
     * standalone branch menus) get menu_product_skus created for each product's
     * active SKUs using productSku.selling_price as the initial price. Master
     * menus skip SKU creation — they are pure templates and cloneToBranch reads
     * variants directly from product.skus, not from master.menuProductSkus.
     *
     * The previous condition (`master_menu_id !== null`) was buggy: a menu
     * created directly under a branch (is_master=false, master_menu_id=null)
     * was wrongly treated as a master and had no SKUs generated, leaving the
     * shop-side menu detail with zero variants.
     *
     * @param  array<int, string>  $productIds
     * @return Collection<int, MenuProduct>
     */
    public function addProducts(Menu $menu, array $productIds, ?string $menuSectionId = null): Collection
    {
        return DB::transaction(function () use ($menu, $productIds, $menuSectionId) {
            $shouldCreateSkus = ! $menu->is_master;
            $nextOrder = $this->getNextProductDisplayOrder($menu);
            $created = new Collection;

            foreach ($productIds as $productId) {
                $existing = $menu->menuProducts()
                    ->where('product_id', $productId)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $menuProduct = $menu->menuProducts()->create([
                    'product_id' => $productId,
                    'is_active' => $menu->is_master,
                    'display_order' => $nextOrder++,
                    'menu_section_id' => $menuSectionId,
                ]);

                if ($shouldCreateSkus) {
                    $this->createSkusForMenuProduct($menuProduct);
                }

                $created->push($menuProduct);
            }

            return $created->load(['product.skus', 'menuProductSkus.productSku']);
        });
    }

    public function removeProduct(MenuProduct $menuProduct): bool
    {
        return DB::transaction(function () use ($menuProduct) {
            $menuProduct->menuProductSkus()->delete();

            return $menuProduct->delete();
        });
    }

    public function toggleProduct(MenuProduct $menuProduct): MenuProduct
    {
        $menuProduct->update(['is_active' => ! $menuProduct->is_active]);

        return $menuProduct;
    }

    /**
     * Reorder products by an ordered array of MenuProduct IDs.
     *
     * @param  array<int, string>  $orderedIds
     */
    public function reorderProducts(Menu $menu, array $orderedIds): Menu
    {
        return DB::transaction(function () use ($menu, $orderedIds) {
            foreach ($orderedIds as $index => $id) {
                $menu->menuProducts()
                    ->where('id', $id)
                    ->update(['display_order' => $index + 1]);
            }

            return $menu->load(['menuProducts' => fn ($q) => $q->orderBy('display_order')
                // #3170 — reorder assigns 1..n here, but rows the caller did not
                // list keep their old (often tied) value, so the response still
                // needs the unique-id tie-break.
                ->orderBy('menu_products.id')]);
        });
    }

    /**
     * Reorder branch menus using a 2-phase UPDATE to avoid the (branch_id, priority) unique violation.
     *
     * Phase 1: negate all priorities to free the positive slots.
     * Phase 2: assign sequential 1-based priorities per the ordered IDs.
     *
     * Throws 422 if menu_ids contains duplicates, references unknown menus, or
     * does not cover every non-deleted menu in the branch.
     *
     * @param  array<int, string>  $menuIds  ordered IDs from the UI
     */
    public function reorderMenus(string $organizationId, string $branchId, array $menuIds, ?string $brandId = null): void
    {
        if (count($menuIds) !== count(array_unique($menuIds))) {
            throw ValidationException::withMessages([
                'menu_ids' => ['menu_ids must not contain duplicate IDs.'],
            ]);
        }

        $branchTotal = Menu::where('branch_id', $branchId)
            ->whereHas('organization', fn ($q) => $q->where('id', $organizationId))
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->count();

        $matchedCount = Menu::where('branch_id', $branchId)
            ->whereHas('organization', fn ($q) => $q->where('id', $organizationId))
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->whereIn('id', $menuIds)
            ->count();

        if ($matchedCount !== count($menuIds)) {
            throw ValidationException::withMessages([
                'menu_ids' => ['One or more menu IDs do not belong to this branch.'],
            ]);
        }

        if ($branchTotal !== count($menuIds)) {
            throw ValidationException::withMessages([
                'menu_ids' => ["menu_ids must cover all branch menus. Expected {$branchTotal} IDs, received ".count($menuIds).'.'],
            ]);
        }

        DB::transaction(function () use ($branchId, $menuIds) {
            // Phase 1: move to negative values to release the positive unique slots
            Menu::where('branch_id', $branchId)
                ->whereIn('id', $menuIds)
                ->update(['priority' => DB::raw('0 - priority')]);

            // Phase 2: assign final 1-based priority
            foreach ($menuIds as $index => $id) {
                Menu::where('branch_id', $branchId)
                    ->where('id', $id)
                    ->update(['priority' => $index + 1]);
            }
        });
    }

    /**
     * Reorder master menus (is_master = true, branch_id IS NULL) for an organization.
     *
     * Uses the same 2-phase UPDATE strategy as reorderMenus to avoid unique constraint
     * violations on (branch_id, priority) — master menus share the branch_id = NULL slot.
     *
     * @param  array<int, string>  $menuIds  ordered IDs from the UI
     */
    public function reorderMasterMenus(string $organizationId, array $menuIds, ?string $brandId = null): void
    {
        if (count($menuIds) !== count(array_unique($menuIds))) {
            throw ValidationException::withMessages([
                'menu_ids' => ['menu_ids must not contain duplicate IDs.'],
            ]);
        }

        $total = Menu::where('organization_id', $organizationId)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->where('is_master', true)
            ->whereNull('branch_id')
            ->count();

        $matched = Menu::where('organization_id', $organizationId)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->where('is_master', true)
            ->whereNull('branch_id')
            ->whereIn('id', $menuIds)
            ->count();

        if ($matched !== count($menuIds)) {
            throw ValidationException::withMessages([
                'menu_ids' => ['One or more menu IDs do not belong to this organization as master menus.'],
            ]);
        }

        if ($total !== count($menuIds)) {
            throw ValidationException::withMessages([
                'menu_ids' => ["menu_ids must cover all master menus. Expected {$total} IDs, received ".count($menuIds).'.'],
            ]);
        }

        DB::transaction(function () use ($organizationId, $menuIds, $brandId) {
            Menu::where('organization_id', $organizationId)
                ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
                ->where('is_master', true)
                ->whereNull('branch_id')
                ->whereIn('id', $menuIds)
                ->update(['priority' => DB::raw('0 - priority')]);

            foreach ($menuIds as $index => $id) {
                Menu::where('organization_id', $organizationId)
                    ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
                    ->where('id', $id)
                    ->update(['priority' => $index + 1]);
            }
        });
    }

    /**
     * Replace the entire menu layout (sections + their products) in one transaction.
     *
     * Each `menu_items[]` entry is a section with its products. A product MAY appear
     * in multiple sections — each (product, section) pair becomes its own row in
     * `menu_products` (one menu_products row per pair). The composite unique on
     * (menu_id, product_id, menu_section_id) blocks duplicates within the same
     * section but allows the same product in different sections.
     *
     * Sections are matched by name within the scope of this menu. New names create
     * new menu_sections rows and attach them to the menu_menu_sections pivot.
     * Names removed from the payload are detached. Products absent from the payload
     * are soft-deleted along with their menu_product_skus.
     *
     * Trade-off you accepted by going with the multi-row approach instead of a
     * separate menu_product_sections pivot: if a product is in 3 sections, it has
     * 3 sets of menu_product_skus. Branch shop overrides/toggles must be repeated
     * per (product, section) row — a single override does NOT propagate to other
     * sections. The composite unique guards against accidental duplicates inside
     * the same section but does not protect against price drift across sections.
     *
     * @param  array<int, array{section_name: string, product_ids: array<int, string>}>  $items
     */
    public function syncLayout(Menu $menu, array $items): Menu
    {
        return DB::transaction(function () use ($menu, $items) {
            $merged = $this->mergeItemsByName($items);
            $sectionIdsByName = $this->reconcileSections($menu, array_keys($merged));
            $this->reconcileMenuProducts($menu, $merged, $sectionIdsByName);

            return $this->findById($menu->id);
        });
    }

    /**
     * Keep menu section attachment/detachment in sync with the admin payload.
     *
     * @param  array<int, array{id: string, display_order?: int}>  $sections
     */
    public function syncSections(Menu $menu, array $sections): Menu
    {
        $pivotData = [];

        foreach ($sections as $index => $section) {
            $pivotData[$section['id']] = [
                'display_order' => $section['display_order'] ?? $index + 1,
            ];
        }

        $this->sectionPivot->sync($menu, $pivotData);

        return $this->findById($menu->id);
    }

    /**
     * Set/clear a menu item-level tax-type override.
     *
     * `null` means inherit from the product.
     */
    public function updateProductTaxType(MenuProduct $menuProduct, ?string $taxTypeId, ?string $brandId = null): MenuProduct
    {
        $menuProduct = MenuProduct::query()
            ->with(['product', 'menu'])
            ->findOrFail($menuProduct->getKey());

        if ($menuProduct->menu !== null) {
            $this->assertTaxTierEditable($menuProduct->menu);
        }

        $menuProduct->update(['tax_type_id' => $this->assignableTaxTypeId($taxTypeId, $brandId)]);

        return $menuProduct->fresh();
    }

    /**
     * #1218 tier 3 — set (or clear) the tax type for a WHOLE menu.
     *
     * Same assignability rule as every other tier: the type must belong to this
     * brand and be ACTIVE. Deactivation blocks new assignment only; lines
     * already pointing at a type keep resolving through it, which is why the
     * resolver applies no is_active filter.
     */
    public function updateMenuTaxType(Menu $menu, ?string $taxTypeId, ?string $brandId = null): Menu
    {
        $this->assertTaxTierEditable($menu);

        $menu->update(['tax_type_id' => $this->assignableTaxTypeId($taxTypeId, $brandId)]);

        return $menu->fresh();
    }

    /**
     * #1218 tier 2 — set (or clear) the tax type for one section IN THIS MENU.
     *
     * Written to the PIVOT, never to `menu_sections`: a section is N:N with
     * menus and heavily reused, so a value on the section itself would follow it
     * into every other menu that shows it.
     */
    public function updateSectionTaxType(Menu $menu, string $menuSectionId, ?string $taxTypeId, ?string $brandId = null): Menu
    {
        $this->assertTaxTierEditable($menu);

        if (! $menu->menuSections()->whereKey($menuSectionId)->exists()) {
            throw ValidationException::withMessages([
                'menu_section_id' => 'That section is not part of this menu.',
            ]);
        }

        $this->sectionPivot->updateExistingPivot($menu, $menuSectionId, [
            'tax_type_id' => $this->assignableTaxTypeId($taxTypeId, $brandId),
        ]);

        return $menu->fresh();
    }

    /**
     * A shop menu that INHERITS FROM HQ takes its structure from HQ, and tax is
     * part of that structure (#1226 — HQ owns tax): `syncFromMaster` rewrites
     * every tier on it from the HQ menu, including writing NULL when HQ holds
     * none. A tier set on the shop copy therefore looks saved in the UI and dies
     * silently at the next sync. That is not hypothetical — HQ lists shop menus
     * on their own tab and renders the same three tax selects there, so the write
     * is one click away and leaves no trace when it vanishes. Reject it and point
     * at the HQ menu, which is the one place the value survives.
     *
     * A shop menu created AT the shop inherits from nothing, so nothing
     * overwrites it: it stays editable. The guard keys off `master_menu_id`
     * (does it inherit?) and never off `branch_id` (is it a shop menu?), or that
     * second kind would be left permanently untaxable.
     */
    private function assertTaxTierEditable(Menu $menu): void
    {
        if ($menu->master_menu_id === null) {
            return;
        }

        throw ValidationException::withMessages([
            'tax_type_id' => 'This shop menu inherits from HQ, and tax is managed by HQ. Set the tax type on the HQ menu — a value set here is overwritten at the next sync.',
        ]);
    }

    /**
     * Null passes through (clearing a tier is always allowed — the line just
     * inherits from the next one down). A non-null id must name an ACTIVE type
     * of this brand, or 422.
     */
    private function assignableTaxTypeId(?string $taxTypeId, ?string $brandId): ?string
    {
        if ($taxTypeId === null) {
            return null;
        }

        $type = $this->taxTypes->findAssignable($taxTypeId, $brandId);

        if ($type === null) {
            throw ValidationException::withMessages([
                'tax_type_id' => 'The selected tax_type_id is invalid.',
            ]);
        }

        return $type->id;
    }

    /**
     * Merge duplicate section names in the payload, preserving first-seen order.
     * Within a section, dedupe product_ids.
     *
     * @param  array<int, array{section_name: string, product_ids?: array<int, string>}>  $items
     * @return array<string, array<int, string>>
     */
    private function mergeItemsByName(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $name = $item['section_name'];
            $merged[$name] = array_values(array_unique(array_merge(
                $merged[$name] ?? [],
                $item['product_ids'] ?? [],
            )));
        }

        return $merged;
    }

    /**
     * Reconcile menu_sections + Menu↔MenuSection pivot for the given names, preserving order.
     *
     * @param  array<int, string>  $names
     * @return array<string, string> section_name => section_id
     */
    private function reconcileSections(Menu $menu, array $names): array
    {
        $existing = $menu->menuSections()->get()->keyBy('name');
        $idsByName = [];
        $pivotData = [];

        foreach ($names as $index => $name) {
            $section = $existing[$name] ?? MenuSection::create([
                'name' => $name,
                'organization_id' => $menu->organization_id,
                'brand_id' => $menu->brand_id,
            ]);
            $idsByName[$name] = $section->id;
            $pivotData[$section->id] = ['display_order' => $index + 1];
        }

        $this->sectionPivot->sync($menu, $pivotData);

        return $idsByName;
    }

    /**
     * Upsert menu_products by (product_id, menu_section_id) pair. Soft-delete
     * rows for pairs that no longer appear anywhere in the payload.
     *
     * @param  array<string, array<int, string>>  $merged  section_name => product_ids[]
     * @param  array<string, string>  $sectionIdsByName  section_name => section_id
     */
    private function reconcileMenuProducts(Menu $menu, array $merged, array $sectionIdsByName): void
    {
        // See addProducts() docblock — every non-master menu needs MenuProductSku
        // rows for new MenuProduct entries so the shop-side menu detail can list
        // variants. Master menus stay sku-less (templates only).
        $shouldCreateSkus = ! $menu->is_master;

        // Include soft-deleted rows so a product moved back to its original
        // section can be restored instead of re-inserted (unique constraint).
        $existing = $menu->menuProducts()->withTrashed()->get()->keyBy(
            fn (MenuProduct $mp) => $mp->product_id.':'.($mp->menu_section_id ?? ''),
        );

        $order = 0;

        foreach ($merged as $name => $productIds) {
            $sectionId = $sectionIdsByName[$name];

            foreach ($productIds as $productId) {
                $order++;
                $key = "{$productId}:{$sectionId}";

                if (isset($existing[$key])) {
                    $mp = $existing[$key];
                    if ($mp->trashed()) {
                        $mp->restore();
                        if ($shouldCreateSkus) {
                            $this->createSkusForMenuProduct($mp);
                        }
                    }
                    $mp->update(['display_order' => $order]);
                    unset($existing[$key]);

                    continue;
                }

                $mp = $menu->menuProducts()->create([
                    'product_id' => $productId,
                    'menu_section_id' => $sectionId,
                    'is_active' => $menu->is_master,
                    'display_order' => $order,
                ]);

                if ($shouldCreateSkus) {
                    $this->createSkusForMenuProduct($mp);
                }
            }
        }

        // Soft-delete remaining (product, section) pairs that are no longer in the payload.
        // Skip rows already soft-deleted from a previous sync.
        foreach ($existing as $orphan) {
            if ($orphan->trashed()) {
                continue;
            }
            $orphan->menuProductSkus()->delete();
            $orphan->delete();
        }
    }

    /**
     * Mirror the master's section layout onto the branch — attach what's
     * missing, follow the master's display_order + tax tier on what's shared,
     * and DETACH what the master no longer has.
     *
     * The detach is the whole point (#1233 follow-up). This used to attach and
     * update only, documented as "leave shop-only sections attached". But a
     * cloned menu has no shop-only sections to protect: shops cannot add
     * sections to a clone (there is no branch-side section editor — same ruling
     * as #1226 for tax), so a section on the branch that the master does not
     * have is always debris — left over from a master whose layout HQ has since
     * changed. Keeping it meant the shop rendered a section HQ had removed, and
     * because clone-time uses sync() (which detaches) while this path did not,
     * the extra only appeared AFTER someone pressed sync: the first clone looked
     * right and re-syncing grew the menu. Observed live as branch menu ランチ
     * carrying 10 sections against its master's 9, the extra one holding no
     * products at all.
     *
     * Products are NOT touched here. A section detached while it still holds
     * branch menu_products would strand those rows pointing at a section the
     * menu no longer has, so they are left for the product-mirroring steps in
     * syncFromMaster (Step 2 / 2b), which soft-delete rows whose master row is
     * gone. Sections carrying products are therefore reported to the caller
     * instead of being silently unhooked.
     *
     * @return list<string> ids of sections detached from the branch menu
     */
    private function syncSectionLayoutFromMaster(Menu $branchMenu): array
    {
        $masterMenu = Menu::with('menuSections')->find($branchMenu->master_menu_id);
        if ($masterMenu === null) {
            return [];
        }

        $pivotData = [];
        foreach ($masterMenu->menuSections as $section) {
            // #1227 — mirror the section tax tier, not just the ordering. The
            // branch is a mirror of HQ (there is no branch-side editor for tax,
            // by ruling in #1226), so re-syncing must also CLEAR a value HQ has
            // cleared — hence this writes the master's value even when null
            // rather than only filling blanks.
            $pivotData[$section->id] = [
                'display_order' => (int) $section->pivot->display_order,
                'tax_type_id' => $section->pivot->tax_type_id,
            ];
        }

        $result = $this->sectionPivot->sync($branchMenu, $pivotData);

        return array_map(strval(...), $result['detached']);
    }

    // =========================================================================
    //  Master Menu
    // =========================================================================

    public function createMasterMenu(array $data): Menu
    {
        $data['is_master'] = true;
        $data['branch_id'] = null;

        return $this->create($data);
    }

    public function listMasterMenus(string $orgId, ?string $brandId = null): LengthAwarePaginator
    {
        return $this->list([
            'organization_id' => $orgId,
            'brand_id' => $brandId,
            'is_master' => true,
        ]);
    }

    public function cloneToBranch(Menu $masterMenu, string $branchId, array $overrides = []): Menu
    {
        if (! $masterMenu->is_master) {
            throw new MenuOperationException('Only master menus can be cloned to branches.');
        }

        // Master menus can be cloned once they're past the review gate.
        // Both Approved and Active count as "ready" — Active was added so a
        // master that has already gone live can still spawn new branch copies
        // (e.g. when a new shop opens after the master is already serving).
        $this->assertStatus(
            $masterMenu,
            [MenuStatusEnum::Approved, MenuStatusEnum::Active],
            'clone to branch',
        );

        $existingClone = Menu::where('master_menu_id', $masterMenu->id)
            ->where('branch_id', $branchId)
            ->first();

        if ($existingClone) {
            throw new MenuOperationException('This master menu has already been cloned to this branch.');
        }

        return DB::transaction(function () use ($masterMenu, $branchId, $overrides) {
            $menuData = array_merge([
                'organization_id' => $masterMenu->organization_id,
                'brand_id' => $masterMenu->brand_id,
                'branch_id' => $branchId,
                'name' => $masterMenu->name,
                'description' => $masterMenu->description,
                'valid_from' => $masterMenu->valid_from,
                'valid_to' => $masterMenu->valid_to,
                'priority' => $this->getNextPriority($branchId),
                // Branches now land at Active so the shop can serve them
                // without an additional approve+activate round-trip after clone.
                'status' => MenuStatusEnum::Active->value,
                'is_master' => false,
                'master_menu_id' => $masterMenu->id,
                'last_synced_at' => now(),
                // #1235 — mirror the master's routing, exactly as syncFromMaster
                // does. Omitting the column let the DB default ('Both') stand,
                // and 'Both' matches every service-type filter — so a
                // Takeaway-only master was served to dine-in guests from the
                // moment it was cloned (a clone lands Active, so there is no
                // grace period before the shop's first sync).
                'service_type' => $masterMenu->service_type,
                // #1227 — the whole-menu tax tier (#1218). Customers are served
                // the BRANCH menu, so a tier left behind here is a tier that
                // never reaches a bill.
                'tax_type_id' => $masterMenu->tax_type_id,
            ], $overrides);

            $branchMenu = Menu::create($menuData);

            $masterMenu->load([
                'menuSections',
                'menuProducts.product.skus',
                'schedules',
            ]);

            // Copy menu_menu_sections pivot — branches share section rows with master
            $sectionPivot = [];
            foreach ($masterMenu->menuSections as $section) {
                $sectionPivot[$section->id] = [
                    'display_order' => (int) $section->pivot->display_order,
                    // #1227 — the section-in-this-menu tax tier (#1218) lives on
                    // the pivot, so copying the pivot without it silently drops
                    // the tier for every branch.
                    'tax_type_id' => $section->pivot->tax_type_id,
                ];
            }
            if (! empty($sectionPivot)) {
                $this->sectionPivot->sync($branchMenu, $sectionPivot);
            }

            // Copy all non-deleted schedules from master — each branch menu owns
            // its own schedule rows so branch_schedule_overrides can target them.
            foreach ($masterMenu->schedules as $schedule) {
                $branchMenu->schedules()->create([
                    'start_time' => $schedule->getRawOriginal('start_time'),
                    'end_time' => $schedule->getRawOriginal('end_time'),
                    // #1234 — the date window decides whether this schedule is a
                    // CAMPAIGN or a permanent one: the resolver reads NULL as
                    // "no bound", so dropping these ran a Tết menu all year.
                    'start_date' => $schedule->start_date,
                    'end_date' => $schedule->end_date,
                    'days_of_week' => $schedule->days_of_week,
                    'is_active' => $schedule->is_active,
                    'priority' => $schedule->priority,
                    'created_by_id' => auth()->id(),
                    // Origin link — lets syncFromMaster update this row in
                    // place when HQ edits the master window.
                    'master_schedule_id' => $schedule->id,
                ]);
            }

            // One menu_products row per master row. Multi-section is encoded as
            // multiple master rows already (same product_id, different menu_section_id),
            // so the loop just mirrors them 1:1 — and each gets its own SKU set.
            foreach ($masterMenu->menuProducts as $masterMp) {
                // Skip rows whose product was (soft-)deleted.
                if ($masterMp->product === null) {
                    continue;
                }

                $branchMp = $branchMenu->menuProducts()->create([
                    'product_id' => $masterMp->product_id,
                    'menu_section_id' => $masterMp->menu_section_id,
                    'is_active' => $masterMp->is_active,
                    'display_order' => $masterMp->display_order,
                    'master_menu_product_id' => $masterMp->id,
                    // #1227 — the per-item tax override. This one predates
                    // #1218 (plan-043) and has been dropped on every clone
                    // since, so a takeaway menu built the intended way at HQ
                    // has never billed the reduced rate through a branch.
                    'tax_type_id' => $masterMp->tax_type_id,
                ]);

                $activeSkus = $masterMp->product->skus->where('is_active', true);

                foreach ($activeSkus as $productSku) {
                    $branchMp->menuProductSkus()->create([
                        'product_sku_id' => $productSku->id,
                        'selling_price' => $productSku->selling_price,
                        'is_price_overridden' => false,
                        'is_active' => true,
                    ]);
                }
            }

            return $branchMenu->load(['menuProducts.product.skus', 'menuProducts.menuProductSkus.productSku', 'menuProducts.menuSection', 'menuSections', 'masterMenu', 'activeSchedules'])
                ->loadCount($this->menuProductCounts());
        });
    }

    /**
     * Deep-copy a menu into a new Draft menu in the SAME scope (same branch_id /
     * is_master / organization_id / brand_id) — unlike cloneToBranch(), this is
     * NOT a master→branch relationship: the copy carries no master_menu_id,
     * master_schedule_id, or master_menu_product_id links, so it never
     * participates in sync-from-master and is a fully independent menu the HQ
     * user can immediately edit.
     *
     * Copies sections pivot, schedules (raw time columns, no master link), and
     * menu_products. Unlike cloneToBranch() (which always reads variant prices
     * from product.skus because master menus carry no menu_product_skus),
     * duplicate() copies the SOURCE menu_product_skus verbatim when they exist
     * (selling_price + is_price_overridden) so a branch menu's price overrides
     * survive the copy; it only falls back to product.skus defaults for master
     * menus, which have no menu_product_skus of their own.
     */
    public function duplicate(Menu $menu, array $overrides = []): Menu
    {
        return DB::transaction(function () use ($menu, $overrides) {
            $menu->load(['menuSections', 'menuProducts.product.skus', 'menuProducts.menuProductSkus', 'schedules']);

            $menuData = array_merge([
                'organization_id' => $menu->organization_id,
                'brand_id' => $menu->brand_id,
                'branch_id' => $menu->branch_id,
                'name' => "{$menu->name} (Copy)",
                'description' => $menu->description,
                'valid_from' => $menu->valid_from,
                'valid_to' => $menu->valid_to,
                'priority' => $this->getNextPriority($menu->branch_id),
                'status' => MenuStatusEnum::Draft->value,
                'is_master' => $menu->is_master,
                'service_type' => $menu->service_type,
                // #1233 — tax rides the copy like service_type does. Omitting it
                // sent the copy back to the branch/brand default, so duplicating
                // a 軽減税率 menu produced one that billed the standard rate.
                'tax_type_id' => $menu->tax_type_id,
                'cart_timeout_minutes' => $menu->cart_timeout_minutes,
            ], $overrides);

            $copy = Menu::create($menuData);

            // Copy menu_menu_sections pivot — the copy shares the same section rows.
            // Every section here is a NEW attachment (the copy has none yet), so
            // sync() INSERTs exactly the columns listed: a tier left out is a tier
            // set to null, not one inherited from the source. #1233.
            $sectionPivot = [];
            foreach ($menu->menuSections as $section) {
                $sectionPivot[$section->id] = [
                    'display_order' => (int) $section->pivot->display_order,
                    'tax_type_id' => $section->pivot->tax_type_id,
                ];
            }
            if (! empty($sectionPivot)) {
                $this->sectionPivot->sync($copy, $sectionPivot);
            }

            // Copy schedules — independent rows, no master_schedule_id link.
            foreach ($menu->schedules as $schedule) {
                $copy->schedules()->create([
                    'start_time' => $schedule->getRawOriginal('start_time'),
                    'end_time' => $schedule->getRawOriginal('end_time'),
                    'start_date' => $schedule->start_date,
                    'end_date' => $schedule->end_date,
                    'days_of_week' => $schedule->days_of_week,
                    'is_active' => $schedule->is_active,
                    'priority' => $schedule->priority,
                    'created_by_id' => auth()->id(),
                ]);
            }

            $shouldCreateSkus = ! $copy->is_master;

            foreach ($menu->menuProducts as $sourceMp) {
                if ($sourceMp->product === null) {
                    continue;
                }

                $newMp = $copy->menuProducts()->create([
                    'product_id' => $sourceMp->product_id,
                    'tax_type_id' => $sourceMp->tax_type_id,
                    'menu_section_id' => $sourceMp->menu_section_id,
                    'is_active' => $sourceMp->is_active,
                    'display_order' => $sourceMp->display_order,
                ]);

                if (! $shouldCreateSkus) {
                    continue;
                }

                if ($sourceMp->menuProductSkus->isNotEmpty()) {
                    foreach ($sourceMp->menuProductSkus as $sourceSku) {
                        $newMp->menuProductSkus()->create([
                            'product_sku_id' => $sourceSku->product_sku_id,
                            'selling_price' => $sourceSku->selling_price,
                            'is_price_overridden' => $sourceSku->is_price_overridden,
                            'is_active' => $sourceSku->is_active,
                        ]);
                    }
                } else {
                    $this->createSkusForMenuProduct($newMp);
                }
            }

            return $copy->load(['menuProducts.product.skus', 'menuProducts.menuProductSkus.productSku', 'menuProducts.menuSection', 'menuSections', 'activeSchedules'])
                ->loadCount($this->menuProductCounts());
        });
    }

    /**
     * Get master menu_products that are not yet in the branch menu.
     *
     * @return Collection<int, MenuProduct>
     */
    public function checkSyncAvailable(Menu $branchMenu): Collection
    {
        if (! $branchMenu->master_menu_id) {
            return new Collection;
        }

        $masterProducts = MenuProduct::where('menu_id', $branchMenu->master_menu_id)
            ->with('product.skus')
            ->orderBy('display_order')
            // #3170 — tie-break so the sync preview lists master rows in the same
            // sequence syncFromMaster will write them.
            ->orderBy('menu_products.id')
            ->get()
            // Skip rows whose product was (soft-)deleted — not syncable.
            ->filter(fn (MenuProduct $mp) => $mp->product !== null)
            ->values();
        $masterIds = $masterProducts->pluck('id');

        // Include soft-deleted branch products so we don't double-count them as
        // "missing" — they will be restored, not re-inserted, during syncFromMaster.
        $branchRows = $branchMenu->menuProducts()->withTrashed()->get();

        $linkedMasterIds = $branchRows
            ->pluck('master_menu_product_id')
            ->filter()
            ->flip();

        // Branch rows whose master row was recreated by an HQ layout edit (the
        // old master id no longer exists). syncFromMaster RELINKS these to the
        // new master row instead of inserting a duplicate, so a master row
        // covered by a stale same-product row is NOT "new".
        $staleByProduct = $branchRows
            ->filter(fn (MenuProduct $mp) => $mp->master_menu_product_id !== null
                && ! $masterIds->contains($mp->master_menu_product_id))
            ->groupBy('product_id')
            ->map->count()
            ->all();

        return $masterProducts
            ->filter(function (MenuProduct $masterMp) use ($linkedMasterIds, &$staleByProduct) {
                if (isset($linkedMasterIds[$masterMp->id])) {
                    return false;
                }
                if (($staleByProduct[$masterMp->product_id] ?? 0) > 0) {
                    $staleByProduct[$masterMp->product_id]--;

                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * #1227 — mirror ONLY the three menu-side tax tiers from a branch menu's
     * master onto it, and report what moved.
     *
     * This lives here rather than in the console command because `menus`,
     * `menu_menu_sections` and `menu_products` are the `menu` aggregate, and
     * this service is one of its registered boundaries. A command writing those
     * models directly is a new ad-hoc write site, which is exactly what the
     * domain-mutation guard exists to catch — and it did.
     *
     * Deliberately NOT syncFromMaster: that also relinks stale rows, deactivates
     * branch rows whose master disappeared and reorders sections. An operator
     * running a tax backfill did not ask for layout changes.
     *
     * MIRROR, not fill-blanks: the master's value is written even when NULL, so
     * a tier HQ has cleared clears on the branch too.
     *
     * @return array{menu:int, section:int, item:int} how many tiers changed
     */
    public function mirrorTaxFromMaster(Menu $branchMenu, bool $apply = true): array
    {
        $master = $branchMenu->masterMenu;

        if ($master === null) {
            return ['menu' => 0, 'section' => 0, 'item' => 0];
        }

        $changed = ['menu' => 0, 'section' => 0, 'item' => 0];

        $masterSectionTax = $master->menuSections
            ->mapWithKeys(fn ($s) => [$s->id => $s->pivot->tax_type_id])
            ->all();

        $masterItemTax = MenuProduct::query()
            ->where('menu_id', $master->id)
            ->pluck('tax_type_id', 'id')
            ->all();

        $itemUpdates = [];
        $branchItems = MenuProduct::query()
            ->where('menu_id', $branchMenu->id)
            ->whereNotNull('master_menu_product_id')
            ->get(['id', 'master_menu_product_id', 'tax_type_id']);

        foreach ($branchItems as $item) {
            if (! array_key_exists($item->master_menu_product_id, $masterItemTax)) {
                continue;
            }
            if ($item->tax_type_id !== $masterItemTax[$item->master_menu_product_id]) {
                $itemUpdates[$item->id] = $masterItemTax[$item->master_menu_product_id];
                $changed['item']++;
            }
        }

        if ($branchMenu->tax_type_id !== $master->tax_type_id) {
            $changed['menu']++;
        }

        $sectionUpdates = [];
        foreach ($branchMenu->menuSections as $section) {
            // A section that exists only on the branch has no master value to
            // mirror; leaving it alone is correct, not an oversight.
            if (! array_key_exists($section->id, $masterSectionTax)) {
                continue;
            }
            if ($section->pivot->tax_type_id !== $masterSectionTax[$section->id]) {
                $changed['section']++;
            }
            $sectionUpdates[$section->id] = $masterSectionTax[$section->id];
        }

        if (! $apply || array_sum($changed) === 0) {
            return $changed;
        }

        DB::transaction(function () use ($branchMenu, $master, $sectionUpdates, $itemUpdates): void {
            $branchMenu->update(['tax_type_id' => $master->tax_type_id]);

            foreach ($sectionUpdates as $sectionId => $taxTypeId) {
                $this->sectionPivot->updateExistingPivot($branchMenu, (string) $sectionId, ['tax_type_id' => $taxTypeId]);
            }

            foreach ($itemUpdates as $itemId => $taxTypeId) {
                MenuProduct::whereKey($itemId)->update(['tax_type_id' => $taxTypeId]);
            }
        });

        return $changed;
    }

    /**
     * Repair the two clone-time drifts fixed by #1234 and #1235 on a branch menu
     * that was cloned BEFORE those fixes landed.
     *
     * Both are silent and both change what a customer is shown:
     *
     *   service_type   — the column was never passed on clone, so the DB default
     *                    'Both' stood, and 'Both' matches every service-type
     *                    filter. A Takeaway-only menu is being served to dine-in
     *                    guests right now, at the reduced rate its items carry.
     *   schedule dates — start_date/end_date were dropped on clone, and the
     *                    resolver reads NULL as "no bound", so a campaign menu
     *                    runs permanently instead of only inside its window.
     *
     * Deliberately NOT a call to syncFromMaster, even though sync now repairs
     * both. Sync also relinks products, deactivates rows the master dropped,
     * deletes stale schedules and reorders sections — real layout changes that
     * someone running a command called "repair clone drift" did not ask for and
     * would not think to review. This touches exactly three columns.
     *
     * Mirrors, including when the master's value is NULL: a shop cannot set
     * either of these itself (service_type is HQ-owned per #1226, and
     * branch_schedule_overrides carries no date columns at all), so there is no
     * shop decision to preserve — and without the NULL case a window HQ removed
     * could never be cleared.
     *
     * @return array{service_type: int, schedule_dates: int}
     */
    public function repairCloneDriftFromMaster(Menu $branchMenu, bool $apply = true): array
    {
        $master = $branchMenu->masterMenu;
        $changed = ['service_type' => 0, 'schedule_dates' => 0];

        if ($master === null) {
            return $changed;
        }

        // getRawOriginal, not the accessor: the customer-facing query reads the
        // raw column, so that is the only value worth comparing.
        $serviceTypeDrifted = $branchMenu->getRawOriginal('service_type') !== $master->getRawOriginal('service_type');

        if ($serviceTypeDrifted) {
            $changed['service_type'] = 1;
        }

        $masterScheduleDates = $master->schedules
            ->mapWithKeys(fn (MenuSchedule $s) => [$s->id => [
                'start_date' => $s->getRawOriginal('start_date'),
                'end_date' => $s->getRawOriginal('end_date'),
            ]])
            ->all();

        $scheduleUpdates = [];

        foreach ($branchMenu->schedules as $branchSchedule) {
            if ($branchSchedule->master_schedule_id === null) {
                // A shop-created schedule with no master. It has no HQ window to
                // mirror, so leaving it alone is the only safe reading.
                continue;
            }

            if (! array_key_exists($branchSchedule->master_schedule_id, $masterScheduleDates)) {
                continue;
            }

            $want = $masterScheduleDates[$branchSchedule->master_schedule_id];

            if ($branchSchedule->getRawOriginal('start_date') === $want['start_date']
                && $branchSchedule->getRawOriginal('end_date') === $want['end_date']) {
                continue;
            }

            $scheduleUpdates[$branchSchedule->id] = $want;
            $changed['schedule_dates']++;
        }

        if (! $apply || array_sum($changed) === 0) {
            return $changed;
        }

        DB::transaction(function () use ($branchMenu, $master, $serviceTypeDrifted, $scheduleUpdates) {
            if ($serviceTypeDrifted) {
                $branchMenu->update(['service_type' => $master->getRawOriginal('service_type')]);
            }

            foreach ($scheduleUpdates as $scheduleId => $dates) {
                MenuSchedule::whereKey($scheduleId)->update($dates);
            }
        });

        return $changed;
    }

    /**
     * Detach sections a branch menu carries that its master does not have.
     *
     * The forward fix lives in syncSectionLayoutFromMaster (that path used to
     * attach-and-update only, so it never removed a section HQ had dropped).
     * But a fix there does not reach a menu that already holds the debris:
     * nothing syncs on a schedule — it waits for someone in the shop to press a
     * button, which for most shops means never. Same reasoning as the sibling
     * RepairBranchMenuCloneDrift, and deliberately NOT bolted onto it: that
     * command's docblock scopes it to three columns and says outright that
     * reordering sections is a layout change its callers did not ask for.
     *
     * Section rows themselves are never deleted — only the menu↔section link.
     * A menu_section is shared by many menus (that is why the tax tier lives on
     * the pivot), so deleting one to tidy a single branch menu would strip it
     * from every other menu using it.
     *
     * Sections still holding live branch products are reported but NOT detached
     * unless $force: detaching one strands its menu_products pointing at a
     * section the menu no longer has. The observed debris holds no products at
     * all, so the default path clears it without that risk; a section that does
     * hold products is a real layout question for a human.
     *
     * @return array{detached: int, skipped_with_products: int, sections: list<array{id: string, name: string, products: int, detached: bool}>}
     */
    public function repairSectionDriftFromMaster(Menu $branchMenu, bool $apply = true, bool $force = false): array
    {
        $result = ['detached' => 0, 'skipped_with_products' => 0, 'sections' => []];

        $master = Menu::with('menuSections')->find($branchMenu->master_menu_id);

        if ($master === null) {
            return $result;
        }

        $masterSectionIds = $master->menuSections->pluck('id')->map(strval(...))->all();

        $extras = $branchMenu->menuSections->filter(
            fn (MenuSection $section) => ! in_array((string) $section->id, $masterSectionIds, true)
        );

        $toDetach = [];

        foreach ($extras as $section) {
            $productCount = $branchMenu->menuProducts()
                ->where('menu_section_id', $section->id)
                ->count();

            $willDetach = $productCount === 0 || $force;

            $result['sections'][] = [
                'id' => (string) $section->id,
                'name' => (string) $section->name,
                'products' => $productCount,
                'detached' => $willDetach,
            ];

            if (! $willDetach) {
                $result['skipped_with_products']++;

                continue;
            }

            $toDetach[] = (string) $section->id;
            $result['detached']++;
        }

        if (! $apply || $toDetach === []) {
            return $result;
        }

        DB::transaction(function () use ($branchMenu, $toDetach) {
            foreach ($toDetach as $sectionId) {
                // Products first: a row left pointing at a detached section
                // renders under no section at the shop. Only reached with
                // --force, since the default path detaches empty sections only.
                $stranded = $branchMenu->menuProducts()
                    ->where('menu_section_id', $sectionId)
                    ->get();

                foreach ($stranded as $orphan) {
                    $orphan->menuProductSkus()->delete();
                    $orphan->delete();
                }
            }

            $this->sectionPivot->detach($branchMenu, $toDetach);
        });

        return $result;
    }

    public function syncFromMaster(Menu $branchMenu): Menu
    {
        if (! $branchMenu->master_menu_id) {
            throw new MenuOperationException('This menu is not cloned from a master menu.');
        }

        return DB::transaction(function () use ($branchMenu) {
            // Rows whose product was (soft-)deleted are excluded up front —
            // their branch counterparts drop into the stale set below and get
            // deactivated, and we never dereference a null product.
            $masterProducts = MenuProduct::where('menu_id', $branchMenu->master_menu_id)
                ->with('product.skus')
                ->orderBy('display_order')
                // #3170 — see checkSyncAvailable: same master rows, same order,
                // or the preview and the write disagree on tied rows.
                ->orderBy('menu_products.id')
                ->get()
                ->filter(fn (MenuProduct $mp) => $mp->product !== null)
                ->values();
            $masterIds = $masterProducts->pluck('id');

            // Step 0: Mirror master sections — attach missing ones, follow the
            // master's order/tax, and detach ones the master dropped.
            $detachedSectionIds = $this->syncSectionLayoutFromMaster($branchMenu);

            $branchRows = $branchMenu->menuProducts()->withTrashed()->get();
            $byMasterId = $branchRows
                ->filter(fn (MenuProduct $mp) => $mp->master_menu_product_id !== null)
                ->keyBy('master_menu_product_id');

            // Branch rows whose master row disappeared. HQ layout edits (e.g.
            // moving a product to another section) soft-delete + recreate the
            // master row under a NEW id, so "gone" usually means "recreated".
            // These rows are relink candidates — reusing them keeps the shop's
            // is_active state and price overrides and prevents duplicates.
            $staleRows = $branchRows
                ->filter(fn (MenuProduct $mp) => $mp->master_menu_product_id !== null
                    && ! $masterIds->contains($mp->master_menu_product_id));
            $claimedIds = [];

            foreach ($masterProducts as $masterMp) {
                $branchMp = $byMasterId->get($masterMp->id);

                if ($branchMp === null) {
                    // Relink: prefer a stale row already in the same section,
                    // else any stale row of the same product.
                    $candidates = $staleRows->filter(
                        fn (MenuProduct $mp) => ! in_array($mp->id, $claimedIds, true)
                            && $mp->product_id === $masterMp->product_id
                    );
                    $branchMp = $candidates->first(
                        fn (MenuProduct $mp) => $mp->menu_section_id === $masterMp->menu_section_id
                    ) ?? $candidates->first();
                }

                if ($branchMp !== null) {
                    $claimedIds[] = $branchMp->id;

                    if ($branchMp->trashed()) {
                        $branchMp->restore();
                    }

                    // Follow the master's placement + ordering; NEVER touch
                    // is_active — bật/tắt món là quyền của shop và phải sống
                    // sót qua mọi lần đồng bộ.
                    $branchMp->update([
                        'master_menu_product_id' => $masterMp->id,
                        'menu_section_id' => $masterMp->menu_section_id,
                        'display_order' => $masterMp->display_order,
                        // #1227 — tax follows the master like placement does,
                        // NOT like is_active. is_active is the shop's decision
                        // and survives sync; the tax rate is not the shop's to
                        // decide (#1226), so re-syncing repairs a branch that
                        // was cloned before this fix.
                        'tax_type_id' => $masterMp->tax_type_id,
                    ]);

                    // A row restored from soft-delete lost its SKUs when it was
                    // removed — recreate them so the variant list isn't empty.
                    // restoreOrCreate sidesteps the unique index still held by
                    // any soft-deleted SKU rows on this menu_product.
                    if ($branchMp->menuProductSkus()->count() === 0) {
                        foreach ($masterMp->product->skus->where('is_active', true) as $productSku) {
                            // Sync: a re-appeared SKU lands inactive (shop enables).
                            $this->restoreOrCreateMenuProductSku($branchMp, $productSku, false);
                        }
                    }

                    continue;
                }

                // Genuinely new product — mirror the master's placement, but
                // land INACTIVE ("HQ thêm ≠ shop bán ngay"): the shop decides
                // whether to sell it and enables it via toggle. The section +
                // order still follow the master so it shows in the right slot
                // once turned on.
                $branchMp = $branchMenu->menuProducts()->create([
                    'product_id' => $masterMp->product_id,
                    'menu_section_id' => $masterMp->menu_section_id,
                    'is_active' => false,
                    'display_order' => $masterMp->display_order,
                    'master_menu_product_id' => $masterMp->id,
                    'tax_type_id' => $masterMp->tax_type_id,   // #1227
                ]);
                $claimedIds[] = $branchMp->id;

                foreach ($masterMp->product->skus->where('is_active', true) as $productSku) {
                    // Sync: a brand-new product's SKUs land inactive (shop enables).
                    $this->restoreOrCreateMenuProductSku($branchMp, $productSku, false);
                }
            }

            // Step 1b: MIRROR "variant off". A ProductSku disabled at HQ can
            // never be sold, so any active MenuProductSku on this branch menu
            // that points at an inactive ProductSku is deactivated. Normally the
            // toggle cascades this at HQ (ProductSkuService), but sync is the
            // catch-up for menus that predate the cascade or drifted. We only
            // turn rows OFF — re-enabling a variant stays the shop's call, same
            // as the is_active handling above.
            $branchMenu->menuProducts()
                ->whereHas('menuProductSkus.productSku', fn ($q) => $q->where('is_active', false))
                ->with(['menuProductSkus' => fn ($q) => $q->where('is_active', true)->with('productSku:id,is_active')])
                ->get()
                ->each(function (MenuProduct $mp): void {
                    foreach ($mp->menuProductSkus as $mps) {
                        if ($mps->productSku !== null && ! $mps->productSku->is_active) {
                            $mps->update(['is_active' => false]);
                        }
                    }
                });

            // Step 2: REMOVE TRUE orphans — linked to a vanished master row and
            // not reclaimed above (the product really left the master menu). A
            // cloned menu mirrors its master exactly, so a product HQ dropped is
            // soft-deleted here rather than left lingering as an inactive row.
            // Soft-delete keeps it recoverable: if HQ re-adds the product, the
            // relink step above restores this row (SKUs included).
            foreach ($staleRows as $orphan) {
                if (in_array($orphan->id, $claimedIds, true) || $orphan->trashed()) {
                    continue;
                }
                $orphan->menuProductSkus()->delete();
                $orphan->delete();
            }

            // Step 2b: REMOVE UNLINKED rows (master_menu_product_id IS NULL)
            // whose product is not in the master menu. Shops cannot add their
            // own products to a cloned menu, so an unlinked row is a lost-link
            // orphan (or leftover from an old clone) — a cloned menu must mirror
            // its master, and such rows never surface in Step 2's stale set.
            // Soft-delete so nothing extra shows at the shop.
            $masterProductIds = $masterProducts->pluck('product_id');
            $unlinkedOrphans = $branchRows->filter(
                fn (MenuProduct $mp) => $mp->master_menu_product_id === null
                    && ! $mp->trashed()
                    && ! $masterProductIds->contains($mp->product_id)
            );
            foreach ($unlinkedOrphans as $orphan) {
                $orphan->menuProductSkus()->delete();
                $orphan->delete();
            }

            // Step 2c: REMOVE rows stranded in a section Step 0 just detached.
            // Steps 2/2b key off the MASTER PRODUCT, so a product HQ still sells
            // — just moved out of a section HQ deleted — is claimed by the relink
            // above and survives them both, while its section is gone from the
            // menu. That row renders under no section at the shop. Re-read from
            // the DB rather than filtering $branchRows: the relink step has been
            // updating menu_section_id since that snapshot was taken, so a row
            // that has since been moved INTO a live section must not be caught
            // here. Soft-delete, matching the steps above.
            if ($detachedSectionIds !== []) {
                $stranded = $branchMenu->menuProducts()
                    ->whereIn('menu_section_id', $detachedSectionIds)
                    ->get();

                foreach ($stranded as $orphan) {
                    $orphan->menuProductSkus()->delete();
                    $orphan->delete();
                }
            }

            // Step 3: MIRROR schedules from master. The branch menu's schedule
            // rows are HQ mirrors (per-shop time tweaks live in
            // branch_schedule_overrides, which COALESCE over these rows), so
            // sync makes branch == master: update linked rows in place, adopt
            // legacy unlinked rows by content, create what's missing, and drop
            // stale leftovers — HQ time edits no longer pile up as extra rows.
            $masterMenu = Menu::with('schedules')->find($branchMenu->master_menu_id);

            if ($masterMenu) {
                $branchSchedules = $branchMenu->schedules()->get();
                $scheduleByMasterId = $branchSchedules
                    ->filter(fn (MenuSchedule $bs) => $bs->master_schedule_id !== null)
                    ->keyBy('master_schedule_id');
                $contentKey = fn ($sch) => $sch->getRawOriginal('start_time')
                    .':'.$sch->getRawOriginal('end_time')
                    .':'.json_encode($sch->days_of_week);
                $claimedScheduleIds = [];

                foreach ($masterMenu->schedules->where('is_active', true) as $masterSchedule) {
                    $branchSchedule = $scheduleByMasterId->get($masterSchedule->id);

                    if ($branchSchedule === null) {
                        // Adopt a legacy pre-link row with identical content so
                        // its branch overrides survive the first mirrored sync.
                        $branchSchedule = $branchSchedules->first(
                            fn (MenuSchedule $bs) => ! in_array($bs->id, $claimedScheduleIds, true)
                                && $bs->master_schedule_id === null
                                && $contentKey($bs) === $contentKey($masterSchedule)
                        );
                    }

                    if ($branchSchedule !== null) {
                        $claimedScheduleIds[] = $branchSchedule->id;
                        $branchSchedule->update([
                            'start_time' => $masterSchedule->getRawOriginal('start_time'),
                            'end_time' => $masterSchedule->getRawOriginal('end_time'),
                            // #1234 — written even when NULL, so a campaign HQ
                            // shortened stops on time and one HQ made
                            // open-ended can actually be cleared at the branch.
                            'start_date' => $masterSchedule->start_date,
                            'end_date' => $masterSchedule->end_date,
                            'days_of_week' => $masterSchedule->days_of_week,
                            'is_active' => true,
                            'priority' => $masterSchedule->priority,
                            'master_schedule_id' => $masterSchedule->id,
                        ]);

                        continue;
                    }

                    $created = $branchMenu->schedules()->create([
                        'start_time' => $masterSchedule->getRawOriginal('start_time'),
                        'end_time' => $masterSchedule->getRawOriginal('end_time'),
                        'start_date' => $masterSchedule->start_date,   // #1234
                        'end_date' => $masterSchedule->end_date,       // #1234
                        'days_of_week' => $masterSchedule->days_of_week,
                        'is_active' => $masterSchedule->is_active,
                        'priority' => $masterSchedule->priority,
                        'created_by_id' => auth()->id(),
                        'master_schedule_id' => $masterSchedule->id,
                    ]);
                    $claimedScheduleIds[] = $created->id;
                }

                // Stale rows — mirrors of windows HQ removed/changed. Delete so
                // the shop shows exactly the master's windows.
                foreach ($branchSchedules as $stale) {
                    if (! in_array($stale->id, $claimedScheduleIds, true)) {
                        $stale->delete();
                    }
                }
            } else {
                // Master menu was removed at HQ (soft-deleted) — Menu::find()
                // returns null. Branch schedule rows are HQ mirrors, so with no
                // master left there are no live windows to mirror: drop them all
                // instead of silently leaving stale windows on the shop.
                $branchMenu->schedules()->delete();
            }

            // Mirror the master's service_type (店内/持ち帰り/両方) onto the
            // branch so HQ's routing choice reaches the shop on sync — a branch
            // menu is a mirror of its master. Without this the branch keeps a
            // stale explicit value (or its clone-time default) that shadows the
            // effective_service_type inheritance and never follows HQ.
            $update = ['last_synced_at' => now()];
            if ($masterMenu !== null) {
                $update['service_type'] = $masterMenu->service_type;
                // #1227 — the whole-menu tax tier mirrors for the same reason
                // service_type does, and needs it more: customers are served the
                // BRANCH menu, so a tier that stops at the master is a tier that
                // never reaches a bill. Written even when null so clearing at HQ
                // clears at the branch.
                $update['tax_type_id'] = $masterMenu->tax_type_id;
            }
            $branchMenu->update($update);

            return $branchMenu->load(['menuProducts.product.skus', 'menuProducts.menuProductSkus.productSku', 'menuProducts.menuSection', 'menuSections', 'masterMenu', 'activeSchedules'])
                ->loadCount($this->menuProductCounts());
        });
    }

    // =========================================================================
    //  Workflow Actions
    // =========================================================================

    public function submit(Menu $menu): Menu
    {
        $this->assertStatus($menu, [
            MenuStatusEnum::Draft,
            MenuStatusEnum::Rejected,
        ], 'submit for approval');

        if ($menu->menuProducts()->count() === 0) {
            throw new MenuOperationException(
                'Menu must have at least one product before submitting.'
            );
        }

        $menu->update([
            'status' => MenuStatusEnum::Pending->value,
            'rejected_by_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        $menu->logAudit('submitted_for_approval');

        return $menu->load(['menuProducts.product.skus', 'masterMenu'])
            ->loadCount($this->menuProductCounts());
    }

    public function approve(Menu $menu, string $approverId): Menu
    {
        $this->assertStatus($menu, [MenuStatusEnum::Pending], 'approve');

        // Separation-of-duties rule temporarily disabled — single-account dev
        // environments cannot run the full submit → approve flow otherwise.
        // Re-enable before shipping to staging/prod.
        //
        // if ($menu->created_by_id === $approverId) {
        //     throw new \InvalidArgumentException(
        //         'Cannot approve your own menu.'
        //     );
        // }

        $menu->update([
            'status' => MenuStatusEnum::Approved->value,
            'approved_by_id' => $approverId,
            'approved_at' => now(),
            'rejected_by_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ]);

        $menu->logAudit('approved', ['approved_by_id' => $approverId]);

        return $menu->load(['menuProducts.product.skus', 'masterMenu'])
            ->loadCount($this->menuProductCounts());
    }

    public function reject(Menu $menu, string $rejectedById, string $reason): Menu
    {
        $this->assertStatus($menu, [MenuStatusEnum::Pending], 'reject');

        $menu->update([
            'status' => MenuStatusEnum::Rejected->value,
            'rejected_by_id' => $rejectedById,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $menu->logAudit('rejected', [
            'rejected_by_id' => $rejectedById,
            'rejection_reason' => $reason,
        ]);

        return $menu->load(['menuProducts.product.skus', 'masterMenu'])
            ->loadCount($this->menuProductCounts());
    }

    public function activate(Menu $menu): Menu
    {
        $this->assertStatus($menu, [
            MenuStatusEnum::Approved,
            MenuStatusEnum::Inactive,
        ], 'activate');

        $menu->update(['status' => MenuStatusEnum::Active->value]);
        $menu->logAudit('activated');

        return $menu->load(['menuProducts.product.skus', 'masterMenu'])
            ->loadCount($this->menuProductCounts());
    }

    public function deactivate(Menu $menu): Menu
    {
        $this->assertStatus($menu, [MenuStatusEnum::Active], 'deactivate');

        $menu->update(['status' => MenuStatusEnum::Inactive->value]);
        $menu->logAudit('deactivated');

        return $menu->load(['menuProducts.product.skus', 'masterMenu'])
            ->loadCount($this->menuProductCounts());
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function lookup(string $organizationId, ?string $brandId = null): array
    {
        return Menu::where('organization_id', $organizationId)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->where('status', MenuStatusEnum::Active->value)
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function masterMenuDropdown(string $organizationId, ?string $brandId = null): array
    {
        return Menu::where('organization_id', $organizationId)
            ->when($brandId, fn ($q) => $q->where('brand_id', $brandId))
            ->where('is_master', true)
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    // =========================================================================
    //  Shop-side helpers
    // =========================================================================

    /**
     * #3163 — các SECTION của một menu, kèm số món mỗi section.
     *
     * Đây là nửa cho phép POS thôi tải cả thực đơn: thanh pill dựng từ đây nên
     * nó ĐÚNG VÀ ĐỦ dù menu to cỡ nào, còn món thì tải theo từng section.
     *
     * Chi phí không phụ thuộc số món: một truy vấn GROUP BY trên
     * `menu_products`, không nạp quan hệ nào. Trước bản này, muốn biết có những
     * section nào thì POS phải kéo về đủ 100% số dòng rồi tự gom — menu 89
     * dòng là 638 KB, và query có `refetchInterval` 60 giây.
     *
     * Nhóm CHƯA XẾP (`menu_section_id IS NULL`) trả về với `id = null` chứ
     * không bị bỏ: món không thuộc section nào vẫn phải bán được, và một thanh
     * pill lặng lẽ giấu chúng chính là #3159 ở dạng khác.
     *
     * Đếm áp đúng bộ lọc của {@see listBranchMenuProducts} — nếu không, pill
     * hiện "12 món" rồi mở ra thấy 9, và người dùng sẽ tin con số nào?
     *
     * @return list<array{id: string|null, name: string|null, display_order: int, products_count: int}>
     */
    public function listBranchMenuSections(Menu $menu): array
    {
        $counts = $menu->menuProducts()
            ->whereHas('product', fn ($p) => $p->where('status', ProductStatusEnum::Active->value))
            ->selectRaw('menu_section_id, COUNT(*) as products_count')
            ->groupBy('menu_section_id')
            ->pluck('products_count', 'menu_section_id');

        // Section lấy từ HAI nguồn, hợp lại. Chỉ một nguồn là bỏ sót:
        //
        //   pivot `menu_menu_section`  — có cả section RỖNG (quán vừa tạo, chưa
        //                                xếp món), và mang `display_order` thật
        //   `menu_products.menu_section_id` — có cả section ĐANG CÓ MÓN nhưng
        //                                chưa gắn vào pivot; đo được: fixture
        //                                của `ShopMenuOperationsTest` dựng đúng
        //                                hình dạng đó, và dữ liệu quán cũng vậy
        //
        // Bỏ nguồn thứ hai là làm món biến mất khỏi thanh pill — tức #3159 tái
        // diễn ở dạng khác, chỉ khó thấy hơn vì lần này lỗi nằm ở phía section.
        $pivot = $menu->menuSections->keyBy(fn ($section) => (string) $section->id);

        $missingIds = collect($counts->keys())
            ->filter(fn ($id) => filled($id) && ! $pivot->has((string) $id))
            ->values();

        $extra = $missingIds->isEmpty()
            ? collect()
            : MenuSection::query()->whereIn('id', $missingIds)->get()->keyBy(fn ($section) => (string) $section->id);

        $sections = [];

        foreach ($pivot as $id => $section) {
            $sections[] = [
                'id' => $id,
                'name' => $section->localizedName ?? $section->name,
                // Section RỖNG vẫn trả về: quán đã tạo nó, và một pill "0 món"
                // nói ra điều đó — giấu đi thì người dựng menu tưởng chưa lưu.
                'display_order' => (int) ($section->pivot->display_order ?? 0),
                'products_count' => (int) ($counts[$id] ?? 0),
            ];
        }

        foreach ($extra as $id => $section) {
            $sections[] = [
                'id' => $id,
                'name' => $section->localizedName ?? $section->name,
                // Không có hàng pivot thì không có thứ tự do quán đặt. Xếp sau
                // nhóm có thứ tự, trước nhóm chưa xếp.
                'display_order' => PHP_INT_MAX - 1,
                'products_count' => (int) ($counts[$id] ?? 0),
            ];
        }

        $unassigned = (int) ($counts[null] ?? $counts[''] ?? 0);

        if ($unassigned > 0) {
            // Cuối thanh, và `id = null` là hợp đồng với client: gọi
            // `?section_id=none` để lấy đúng nhóm này.
            $sections[] = [
                'id' => null,
                'name' => null,
                'display_order' => PHP_INT_MAX,
                'products_count' => $unassigned,
            ];
        }

        usort($sections, fn ($a, $b) => $a['display_order'] <=> $b['display_order']);

        return $sections;
    }

    /**
     * Paginate menu_products inside a branch menu with their SKU pricing rows.
     *
     * Free-text `search` is a single input that matches ANY of three fields:
     *   - Product.name                (e.g. "Americano")
     *   - ProductSku.name             (variant label, e.g. "Size L", "Nóng")
     *   - ProductSku.sku              (barcode / SKU code printed on nhãn)
     *
     * Uses `whereHas` so paginate() counts are stable — no duplicate rows
     * when a product has multiple matching SKUs. See ShopMenuOperationsTest
     * "filters menu products by Product.name, ProductSku.name, and
     * ProductSku.sku" for the matrix.
     *
     * @param  array{search?: string, is_active?: bool, per_page?: int}  $filters
     */
    public function listBranchMenuProducts(Menu $menu, array $filters = []): LengthAwarePaginator
    {
        $query = $menu->menuProducts()->with(array_merge([
            'product',
            // Full gallery for the order picker carousel. ProductResource
            // serialises both `gallery` (full list) and `image_url` (first one).
            'product.gallery',
            // ProductType so ProductResource can expose `product_type_code`.
            // POS branches on the lowercase 'combo' value to render the
            // combo card treatment.
            'product.productType',
            // plan-019 — categories drive the active_promotion overlay for
            // category-scoped Happy-Hour promotions. MenuController::listProducts
            // reads $mp->product->categories to build the resolver's
            // category_ids; without this eager-load relationLoaded() is false,
            // category_ids falls back to [], and category promotions silently
            // never decorate the menu card (the cart still applies them).
            'product.categories:id',
            'menuProductSkus.productSku',
            'menuSection',
            // Shop-level topping overrides (tier 1 in the 3-tier price chain).
            // Eager-loaded here so MenuProductResource does not fall back to
            // per-row lazy loads (N+1) when serializing toppingOverrides.
            'toppingOverrides',
        ], $this->toppingEagerLoadChain($menu)));

        // #902 — this is the POS / Handy / workstation-handy order picker
        // catalog. Only offer products the order gate will accept; a
        // non-sellable (draft / paused / rejected / never-activated) product
        // must not appear as an addable card (staff would tap it → 422).
        // Menu MANAGEMENT screens use separate endpoints, so this filter is
        // scoped to the ordering surface only.
        $query->whereHas('product', fn ($p) => $p->where('status', ProductStatusEnum::Active->value));

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // #3163 — lọc theo SECTION. `'none'` là nhóm chưa xếp; nó phải là một
        // giá trị tường minh chứ không phải "bỏ trống tham số", vì bỏ trống đã
        // mang nghĩa "mọi section".
        if (isset($filters['section_id']) && $filters['section_id'] !== '') {
            $filters['section_id'] === 'none'
                ? $query->whereNull('menu_section_id')
                : $query->where('menu_section_id', $filters['section_id']);
        }

        // #3163 — tra MỘT món theo SKU. Luồng SỬA MÓN hôm nay dựa vào việc cả
        // thực đơn đã nằm sẵn trong bộ nhớ POS; khi POS thôi tải hết, nó cần
        // một đường hỏi thẳng, nếu không sửa một món đã đặt sẽ hỏng đúng lúc
        // khách đang đứng đó.
        if (! empty($filters['sku_id'])) {
            $query->whereHas('menuProductSkus', fn ($q) => $q->where('product_sku_id', $filters['sku_id']));
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            // Single search input, multi-field match: staff might type a
            // product name, an SKU variant name (e.g. "Size L"), or the raw
            // SKU code printed on the barcode — all three should resolve
            // to the same row. `whereHas` keeps the outer paginate count
            // correct (no duplicate rows from joins).
            //
            // #1223 — the `name` columns matched here are the Astrotomic BASE
            // columns, i.e. one language, written ja → en → vi by preference.
            // The POS DISPLAYS `localizedName()` for the cashier's
            // Accept-Language, so a shop that filled in all three languages
            // showed a Vietnamese cashier Vietnamese names while the search ran
            // against Japanese — typing exactly what was on screen returned
            // nothing. Translations are searched alongside the base column.
            //
            // Deliberately every locale, not just the current one: the ask is
            // "findable in any of the three", and a cashier at a border shop or
            // serving a foreign guest may well type a name in another language
            // than the UI is set to. The current locale still decides how the
            // results are DISPLAYED — that part is unchanged.
            $query->where(function ($q) use ($search): void {
                $q->whereHas('product', function ($pq) use ($search): void {
                    $pq->where('name', 'like', '%'.$search.'%')
                        ->orWhereHas('translations', function ($tq) use ($search): void {
                            $tq->where('name', 'like', '%'.$search.'%');
                        });
                })
                    ->orWhereHas('menuProductSkus.productSku', function ($sq) use ($search): void {
                        $sq->where('name', 'like', '%'.$search.'%')
                            ->orWhere('sku', 'like', '%'.$search.'%')
                            ->orWhereHas('translations', function ($tq) use ($search): void {
                                $tq->where('name', 'like', '%'.$search.'%');
                            });
                    });
            });
        }

        return $query
            ->orderBy('display_order')
            // `display_order` is NOT unique — 104 of one real menu's 127 rows
            // sit on 0 — so it is not a total order, and a paginated query over
            // a partial order is free to hand the same row back on two pages
            // and never hand back another. The POS now walks every page, which
            // turns that from a latent risk into missing dishes. Tie-break on
            // the unique UUIDv7 id, same fix as #2046 / #2109 did for topping
            // sort_order.
            ->orderBy('menu_products.id')
            ->paginate($filters['per_page'] ?? 50);
    }

    /**
     * Apply the #463 service-type gate to a branch-menu listing query.
     *
     * A menu marked Takeaway only shows in the takeaway flow, DineIn only in
     * dine-in; "Both" (the default + every legacy menu) shows in either. A
     * branch menu with NULL service_type inherits its master (HQ) menu's type
     * live; NULL with no master falls back to Both. When $serviceType is null
     * (caller didn't specify), no filter is applied — back-compat.
     *
     * Mirrors CustomerMenuService::getMenuForBranch's gate — keep in sync.
     */
    private function applyServiceTypeGate(Builder $query, ?string $serviceType): void
    {
        if ($serviceType === null) {
            return;
        }

        $wanted = [$serviceType, 'Both'];
        $query->where(function ($q) use ($wanted) {
            // Own value gates directly …
            $q->whereIn('service_type', $wanted)
                // … or NULL = inherit the master menu's type …
                ->orWhere(function ($inherit) use ($wanted) {
                    $inherit->whereNull('service_type')
                        ->where(function ($resolve) use ($wanted) {
                            $resolve->whereHas('masterMenu', fn ($mq) => $mq->whereIn('service_type', $wanted))
                                // … or NULL with no master → fall back to Both.
                                ->orWhereDoesntHave('masterMenu');
                        });
                });
        });
    }

    /**
     * #1756 — stamp the master (HQ) menu's service type onto each row as a
     * scalar `master_service_type` alias, so `MenuResource` can resolve
     * `effective_service_type` for a listing. Without it the POS only ever
     * received the raw nullable column, where NULL means "inherit" — a value
     * no screen can render.
     *
     * Deliberately a subquery and NOT `->with('masterMenu')`: eager-loading
     * that relation flips on the four `relationLoaded('masterMenu')` timeout
     * tiers in MenuResource, which then lazy-load `branch` and
     * `masterMenu.brand` per row, and `schemaArray()` serializes whichever
     * relation is loaded — the same payload blow-up `menuProducts` is already
     * `unset` for at the top of that resource.
     *
     * The COALESCE deliberately lives in the resource, not in here: a
     * standalone branch menu (own type set, no master) yields no subquery row,
     * so folding `menus.service_type` in at this level would return NULL and
     * throw away a value that was set.
     */
    private function masterServiceTypeSubquery(): Builder
    {
        return Menu::query()
            ->from('menus as master_lookup')
            ->select('master_lookup.service_type')
            ->whereColumn('master_lookup.id', 'menus.master_menu_id')
            ->limit(1);
    }

    /**
     * @param  array{status?: string, search?: string, service_type?: string|null, per_page?: int}  $filters
     */
    public function listBranchMenusForShop(string $branchId, array $filters = []): LengthAwarePaginator
    {
        $query = Menu::query()
            ->where('branch_id', $branchId)
            // Every branch menu (cloned from a master OR created standalone on the
            // branch) belongs here. Masters carry branch_id = null so the branch_id
            // filter already excludes them; is_master = false states the real intent.
            ->where('is_master', false)
            ->withCount(['menuProducts as menu_products_count' => fn ($q) => $q->select(DB::raw('count(distinct product_id)'))])
            ->addSelect(['master_service_type' => $this->masterServiceTypeSubquery()]);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $query->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"));

        $this->applyServiceTypeGate($query, $filters['service_type'] ?? null);

        return $query
            ->orderBy('priority')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Ngày thật gần nhất (tính cả hôm nay) rơi vào `$dayOfWeek`, dạng `Y-m-d`.
     *
     * Tách riêng và `private static` để test được qua chính bộ chọn, và để chỗ
     * này không lặng lẽ dùng đồng hồ ứng dụng: `$businessDate` đã là ngày nghiệp
     * vụ của chi nhánh (#1091), hàm chỉ dịch tới trong lịch chứ không đọc giờ.
     *
     * @param  int  $dayOfWeek  0 = Chủ nhật … 6 = thứ Bảy, cùng quy ước với
     *                          bitmask `days_of_week` (bit0 = Chủ nhật).
     */
    private static function resolveNextDateForWeekday(string $businessDate, int $dayOfWeek): string
    {
        $date = CarbonImmutable::createFromFormat('Y-m-d', $businessDate)->startOfDay();
        $delta = ($dayOfWeek - (int) $date->dayOfWeek + 7) % 7;

        return $date->addDays($delta)->format('Y-m-d');
    }

    /**
     * List Active branch menus that have an explicit schedule covering the given
     * day-of-week.
     *
     * A menu is returned only when it has at least one active, non-deleted row
     * in `menu_schedules` whose `days_of_week` bitmask has the day's bit set.
     * Always-on menus (zero schedule rows) are intentionally excluded — this
     * endpoint is strictly schedule-driven.
     *
     * Time-of-day is NOT applied — use `getCurrentMenu()` for the single live
     * menu at the moment.
     *
     * The schedule's CALENDAR WINDOW (`start_date` / `end_date`) IS applied here
     * as of #1970 — and that REVERSES the #1237 ruling of 2026-07-30, which had
     * kept the window customer-facing only so staff could still sell outside it
     * (pre-orders, regulars, fixing someone's mistake). The product decision on
     * 2026-08-06 was that one shop must not give two answers: a campaign menu
     * dated 1–15 Feb is now equally invisible to the guest and to the till in
     * July. If staff again need to sell outside the window, add an explicit
     * permission — do not quietly widen this query back.
     *
     * The bound is BRANCH-AWARE: `branch_schedule_overrides` may narrow or shift
     * what HQ set, same COALESCE shape as the times. NULL on either column means
     * unbounded (no DB default), the same NULL-inversion shape as #1234.
     *
     * Applied at all four reading surfaces — this one, `getCurrentMenu`,
     * CustomerMenuService and MenuScheduleReplicaController — because leaving any
     * one of them behind silently changes what a shop can sell. The replica feed
     * matters most: it is what the LAN POS reads when the internet is down, so a
     * feed without the columns would make the offline till LOOSER than the online
     * one.
     *
     * Bit position matches `getCurrentMenu()`: bit0=Sun … bit6=Sat (Carbon convention).
     *
     * `matched_start_time` / `matched_end_time` are pulled inline via correlated
     * subqueries — single round-trip, no GROUP BY, picks the highest-priority
     * matching schedule (lowest `priority`, then earliest `created_at`) when
     * a menu has several schedules covering the day. The returned times are
     * effective values: shop-level `branch_schedule_overrides` (if any) take
     * precedence over the HQ default via COALESCE. HQ still owns which schedule
     * row wins (priority + created_at); the shop can only widen/narrow its hours.
     *
     * @param  int  $dayOfWeek  0 (Sunday) through 6 (Saturday).
     * @param  array{search?: string, service_type?: string|null, per_page?: int}  $filters
     * @param  string|null  $onDate  Calendar day the window is tested against, `Y-m-d`.
     *                               Defaults to the BRANCH's business date (#1091) —
     *                               never the app clock, or a Tokyo shop read from
     *                               Hanoi answers for the wrong day.
     */
    public function listActiveBranchMenusForShopByDay(
        string $branchId,
        int $dayOfWeek,
        array $filters = [],
        ?string $onDate = null,
    ): LengthAwarePaginator {
        // Bộ chọn ngày hỏi về một THỨ, nhưng luật tháng và luật ngày-cụ-thể chỉ
        // trả lời được cho một NGÀY THẬT. Nếu để `$onDate` là hôm nay trong khi
        // `$dayOfWeek` là thứ khác thì hai vế của cùng một câu hỏi nói về hai
        // ngày khác nhau: hôm nay Chủ nhật 15, bấm "thứ Ba", menu lặp-ngày-15
        // vẫn hiện — mà thứ Ba là ngày 17 và menu đó không bán. Quán chuẩn bị
        // nguyên liệu cho một menu không lên (#1979).
        //
        // Giải ra lần XUẤT HIỆN TIẾP THEO của thứ được hỏi, tính cả hôm nay khi
        // hôm nay đúng thứ đó. "Tiếp theo" chứ không phải "trong tuần này": tuần
        // bắt đầu từ đâu là quy ước theo vùng, còn "thứ Ba gần nhất sắp tới" thì
        // không mơ hồ ở đâu cả.
        $onDate ??= self::resolveNextDateForWeekday(BusinessClock::businessDate($branchId), $dayOfWeek);

        // Effective calendar bound for this branch (#1970) — override when the
        // shop set one, else HQ. The subquery is repeated instead of aliased
        // because the `IS NULL` arm (unbounded) must survive the COALESCE and a
        // The matched schedule row is chosen by HQ priority (HQ owns priority),
        // but the returned start/end times honour any branch-level override —
        // POS/handy/workstation see the effective (shop > HQ) hours for the day.
        // $column is a compile-time constant ('start_time' | 'end_time') so raw
        // interpolation is safe.
        $matchingSchedule = fn (string $column) => MenuSchedule::query()
            ->selectRaw(
                "COALESCE(
                    (SELECT o.{$column} FROM branch_schedule_overrides o
                     WHERE o.menu_schedule_id = menu_schedules.id AND o.branch_id = ?),
                    menu_schedules.{$column}
                )",
                [$branchId]
            )
            ->whereColumn('menu_schedules.menu_id', 'menus.id')
            ->where('menu_schedules.is_active', true)
            // The row whose times get reported must itself be on today —
            // otherwise a menu passes the whereHas on one schedule and reports
            // the hours of an expired or out-of-kind one (#1970, #1979).
            ->tap(fn ($q) => MenuScheduleDateRule::apply($q, $branchId, $dayOfWeek, $onDate))
            ->orderBy('menu_schedules.priority')
            ->orderBy('menu_schedules.created_at')
            ->limit(1);

        $query = Menu::query()
            ->where('menus.branch_id', $branchId)
            // Include standalone branch menus, not just clones — see listBranchMenusForShop().
            ->where('menus.is_master', false)
            ->where('menus.status', MenuStatusEnum::Active->value)
            ->whereHas(
                'activeSchedules',
                fn ($s) => MenuScheduleDateRule::apply($s, $branchId, $dayOfWeek, $onDate)
            )
            ->withCount(['menuProducts as menu_products_count' => fn ($q) => $q->select(DB::raw('count(distinct product_id)'))])
            ->addSelect([
                'matched_start_time' => $matchingSchedule('start_time'),
                'matched_end_time' => $matchingSchedule('end_time'),
                'master_service_type' => $this->masterServiceTypeSubquery(),
            ]);

        $query->when($filters['search'] ?? null, fn ($q, $search) => $q->where('menus.name', 'like', "%{$search}%"));

        $this->applyServiceTypeGate($query, $filters['service_type'] ?? null);

        return $query
            ->orderBy('menus.priority')
            ->paginate($filters['per_page'] ?? 20);
    }

    // =========================================================================
    //  Availability (plan-056) — bật/tắt món, biến thể, cả section
    // =========================================================================
    //
    // ## SET, not TOGGLE — and why that is a correctness requirement
    //
    // `toggleProductForShop` below still exists for admin-web, but every NEW
    // caller goes through the setters. The reason is the workstation: a LAN
    // write is queued in `sync_queue` and pushed with AT-LEAST-ONCE delivery,
    // so a retried "flip it" lands the row back where it started, silently,
    // and the shop finds a dish it turned off is on sale again. "Set it to
    // false" survives any number of replays.
    //
    // ## One write path
    //
    // The toggles are now thin wrappers over the setters, so the disable
    // reason, the `disabled_at` stamp and the audit event can never be skipped
    // by picking the older method. Adding a second place that writes
    // `menu_products.is_active` is how the log starts lying.

    /**
     * Set (not flip) a dish's availability at the shop.
     *
     * `$occurredAt` is when the OPERATOR acted, which for a workstation replay
     * can be hours before now — see BR-MAE03. Callers on a live HTTP request
     * pass null and get now().
     */
    public function setProductActiveForShop(
        MenuProduct $menuProduct,
        bool $isActive,
        MenuAvailabilityActor $actor,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): MenuProduct {
        $this->applyAvailability($menuProduct, $isActive, $actor, $reason);

        if ($menuProduct->isDirty()) {
            $menuProduct->save();
            $this->recordAvailabilityEvent(
                menuProduct: $menuProduct,
                entityType: MenuAvailabilityEntityTypeEnum::MenuProduct,
                entityId: (string) $menuProduct->id,
                isActive: $isActive,
                actor: $actor,
                reason: $reason,
                occurredAt: $occurredAt,
            );
        }

        return $menuProduct;
    }

    /** Set (not flip) one variant's availability at the shop. */
    public function setSkuActiveForShop(
        MenuProductSku $sku,
        bool $isActive,
        MenuAvailabilityActor $actor,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): MenuProductSku {
        $this->applyAvailability($sku, $isActive, $actor, $reason);

        if ($sku->isDirty()) {
            $sku->save();
            $this->recordAvailabilityEvent(
                menuProduct: $sku->menuProduct,
                entityType: MenuAvailabilityEntityTypeEnum::MenuProductSku,
                entityId: (string) $sku->id,
                isActive: $isActive,
                actor: $actor,
                reason: $reason,
                occurredAt: $occurredAt,
            );
        }

        return $sku->load(self::SKU_RESPONSE_LOAD);
    }

    /**
     * Set availability on an EXPLICIT list of menu_product ids inside one menu.
     *
     * This — not "every product of section X" — is what the workstation queues
     * for a bulk toggle, and the difference is not cosmetic. A queued op can
     * sit for hours while the shop is offline; if HQ moves dishes into that
     * section meanwhile, replaying "all of section X" reaches rows the operator
     * never saw and never intended. An explicit id list means a replay lands on
     * exactly the rows that were on screen when the button was pressed.
     *
     * Ids outside `$menu` are dropped, not rejected: HQ removing one dish from
     * the menu must not strand the other forty in the queue behind a 404.
     *
     * @param  list<string>  $menuProductIds
     * @return int rows actually changed
     */
    public function setMenuProductsActiveForShop(
        Menu $menu,
        array $menuProductIds,
        bool $isActive,
        MenuAvailabilityActor $actor,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): int {
        if ($menuProductIds === []) {
            return 0;
        }

        $rows = $menu->menuProducts()
            ->whereIn('id', $menuProductIds)
            ->get();

        $changed = 0;
        foreach ($rows as $row) {
            $before = (bool) $row->is_active;
            $this->setProductActiveForShop($row, $isActive, $actor, $reason, $occurredAt);
            if ($before !== $isActive) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Set availability on an EXPLICIT list of menu_product_sku ids inside one
     * menu — the write behind "turn off size Lớn for this dish".
     *
     * # Why an id list and not "every SKU carrying option value X"
     *
     * Same reason as `setMenuProductsActiveForShop`, and it matters more here.
     * A queued op can sit for hours while the shop is offline. Replaying
     * "every SKU with value X" would reach SKUs HQ added meanwhile — variants
     * the operator never saw. An explicit list lands on exactly the rows that
     * were on screen when the switch was pressed.
     *
     * # Why this is NOT a new override tier on the option value
     *
     * `product_options` / `product_option_values` hang off `product_id`, with
     * no branch column: writing "Lớn is off" there would turn size Lớn off at
     * every branch of the brand. `menu_product_skus` is already per-menu, and
     * menus are per-branch (`menus.branch_id`) — so the shop-scoped address
     * for "this variant is not sellable here" already exists, and an option
     * value is just a NAME FOR A SET of those rows.
     *
     * Keeping it that way means there is exactly ONE gate on selling a variant.
     * A second tier would let "dish on, SKU on, option off" happen, and nothing
     * in the read path would know which of the two answers wins.
     *
     * Ids outside `$menu` are dropped, not rejected — HQ pulling one variant
     * must not strand the rest of the batch behind a 404.
     *
     * @param  list<string>  $menuProductSkuIds
     * @return int rows actually changed
     */
    public function setMenuProductSkusActiveForShop(
        Menu $menu,
        array $menuProductSkuIds,
        bool $isActive,
        MenuAvailabilityActor $actor,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): int {
        if ($menuProductSkuIds === []) {
            return 0;
        }

        $rows = MenuProductSku::query()
            ->whereIn('id', $menuProductSkuIds)
            ->whereHas('menuProduct', fn ($q) => $q->where('menu_id', $menu->id))
            ->with('menuProduct')
            ->get();

        $changed = 0;
        foreach ($rows as $row) {
            $before = (bool) $row->is_active;
            $this->setSkuActiveForShop($row, $isActive, $actor, $reason, $occurredAt);
            if ($before !== $isActive) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Bật/tắt toàn bộ món của một section trong menu shop (issue: nút
     * "bật tất cả món trong 1 section"). Returns the number of rows flipped.
     *
     * Resolves the section to a concrete id list and delegates, so the section
     * button and the workstation replay share one write path — and so the
     * bulk button records the same reason + audit events a single toggle does.
     */
    public function setSectionProductsActiveForShop(
        Menu $menu,
        string $menuSectionId,
        bool $isActive,
        ?MenuAvailabilityActor $actor = null,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): int {
        $ids = $menu->menuProducts()
            ->where('menu_section_id', $menuSectionId)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        return $this->setMenuProductsActiveForShop(
            $menu,
            $ids,
            $isActive,
            $actor ?? MenuAvailabilityActor::system(),
            $reason,
            $occurredAt,
        );
    }

    /**
     * plan-056 — log a topping hide/show.
     *
     * Public because the topping OVERRIDE itself is written by
     * `ShopMenuToppingOverrideService` (that aggregate's boundary), while the
     * availability LOG is this aggregate's. Splitting the write from the log
     * is deliberate: the override table is a pricing/visibility surface with
     * its own owner, and duplicating its write rules here to keep the log
     * local would give the shop two places that decide what a topping costs.
     *
     * `entity_id` carries the topping item; `menuProduct` carries the dish, so
     * a report can group either way.
     */
    public function recordToppingAvailabilityEvent(
        MenuProduct $menuProduct,
        string $toppingGroupItemId,
        bool $isActive,
        MenuAvailabilityActor $actor,
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
    ): void {
        $this->recordAvailabilityEvent(
            menuProduct: $menuProduct,
            entityType: MenuAvailabilityEntityTypeEnum::ToppingItem,
            entityId: $toppingGroupItemId,
            isActive: $isActive,
            actor: $actor,
            reason: $reason,
            occurredAt: $occurredAt,
        );
    }

    /**
     * Stage the availability columns on a MenuProduct or MenuProductSku.
     *
     * The three `disabled_*` columns move as ONE unit with `is_active`: turning
     * something back on clears all three. Leaving a stale reason behind is the
     * failure mode that matters — the POS renders it next to the dish, so a
     * leftover "hết hàng" on a dish that is on sale reads as a bug in the
     * stock, not in us.
     */
    private function applyAvailability(
        MenuProduct|MenuProductSku $row,
        bool $isActive,
        MenuAvailabilityActor $actor,
        ?string $reason,
    ): void {
        if ($isActive) {
            $row->fill([
                'is_active' => true,
                'disabled_reason' => null,
                'disabled_at' => null,
                'disabled_by_name' => null,
            ]);

            return;
        }

        // #3149 — `disabled_at` trả lời "TỪ KHI NÀO món này tắt", nên nó chỉ
        // được đóng dấu ở lượt CHUYỂN TRẠNG THÁI, không phải mọi lượt ghi.
        //
        // Trước bản này nó lấy `now()` mỗi lần. Với một lượt ghi LẶP — cùng giá
        // trị, cùng lý do — model vẫn `isDirty()` ngay khi hai lượt rơi vào hai
        // GIÂY khác nhau, và caller chỉ ghi sự kiện khi dirty. Hệ quả: một lượt
        // phát lại một giây sau đẻ thêm một `menu_availability_events`, tức
        // đúng thứ mà bài "is idempotent" tồn tại để chặn — nó làm phồng con số
        // "món này hết hàng bao nhiêu lần".
        //
        // Nó lộ ra dưới dạng test CHẬP CHỜN, không phải lỗi báo cáo từ quán:
        // ba lượt PUT liên tiếp thường nằm gọn trong một giây, nên bài chỉ đỏ
        // khi máy đủ chậm để chúng vắt qua ranh giây — và `pest --parallel` làm
        // điều đó thành thường xuyên. Một bài đỏ theo tải thì mọi PR sau thành
        // xổ số (#3149: một lượt truy oan cho PR #3146).
        //
        // Giữ dấu thời gian CŨ cũng đúng nghĩa hơn: khoảng "đã tắt bao lâu"
        // phải đo từ lúc nó tắt, không phải từ lượt phát lại gần nhất.
        $alreadyOff = $row->exists && ! (bool) $row->getOriginal('is_active');

        $row->fill([
            'is_active' => false,
            // Column is 255 wide; truncate rather than reject. A reason too
            // long must never be the thing that stops a dish going offline
            // mid-service — the toggle is the point, the words are metadata.
            'disabled_reason' => $this->normalizeDisableReason($reason),
            'disabled_by_name' => $actor->name,
        ]);

        if (! $alreadyOff) {
            $row->disabled_at = now();
        }
    }

    /**
     * Empty / whitespace-only → null. No minimum length is enforced HERE or
     * anywhere else: a cashier mid-service taps a preset chip, and "quá ngắn"
     * on a one-word reason is a validation error that costs service time and
     * protects nothing.
     */
    private function normalizeDisableReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $trimmed = trim($reason);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }

    /**
     * Append one row to the availability log.
     *
     * Only called when the row actually changed, so replaying a sync op writes
     * nothing — the log has to answer "how often was this out of stock", and a
     * retry storm would inflate that number without a single real event.
     */
    private function recordAvailabilityEvent(
        ?MenuProduct $menuProduct,
        MenuAvailabilityEntityTypeEnum $entityType,
        string $entityId,
        bool $isActive,
        MenuAvailabilityActor $actor,
        ?string $reason,
        ?CarbonInterface $occurredAt,
    ): void {
        $branchId = $menuProduct?->menu?->branch_id;
        if ($branchId === null) {
            // A menu with no branch is an HQ master menu; shops do not toggle
            // those, and an event with no branch cannot be reported on.
            return;
        }

        MenuAvailabilityEvent::create([
            'branch_id' => $branchId,
            'menu_product_id' => $menuProduct?->id,
            'entity_type' => $entityType->value,
            'entity_id' => $entityId,
            'is_active' => $isActive,
            'reason' => $isActive ? null : $this->normalizeDisableReason($reason),
            'source' => $actor->source->value,
            'occurred_at' => $this->clampOccurredAt($occurredAt),
            'acted_by_user_id' => $actor->userId,
            'actor_name' => $actor->name,
        ]);
    }

    /**
     * Keep a client-supplied timestamp inside a believable window.
     *
     * The workstation reports when the cashier tapped, which is the number the
     * report has to use (BR-MAE03) — but it comes from a machine whose clock we
     * do not own. A future stamp would sort above everything forever; one from
     * 2019 would silently fall outside every report window. Clamp instead of
     * reject: a wrong clock must not strand a real toggle in the sync queue.
     */
    private function clampOccurredAt(?CarbonInterface $occurredAt): CarbonInterface
    {
        $now = CarbonImmutable::now();

        if ($occurredAt === null) {
            return $now;
        }

        $oldest = $now->subDays(self::AVAILABILITY_EVENT_MAX_BACKDATE_DAYS);

        return match (true) {
            $occurredAt->greaterThan($now) => $now,
            $occurredAt->lessThan($oldest) => $oldest,
            default => $occurredAt,
        };
    }

    /**
     * Toggle is_active on a menu product (shop-side).
     *
     * Kept for admin-web, whose UI is a flip. Now a wrapper so the reason
     * columns and the audit event cannot be bypassed by choosing this method.
     */
    public function toggleProductForShop(MenuProduct $menuProduct, ?MenuAvailabilityActor $actor = null): MenuProduct
    {
        return $this->setProductActiveForShop(
            $menuProduct,
            ! $menuProduct->is_active,
            $actor ?? MenuAvailabilityActor::system(),
        );
    }

    /**
     * Shape of the productSku eager-load carried by every shop-side MenuProductSku
     * response. The shop SKU table needs the variant trio (option value label +
     * parent option name) to render per-row, so include them alongside the core
     * productSku relation.
     *
     * @var list<string>
     */
    private const SKU_RESPONSE_LOAD = [
        'productSku',
        'productSku.optionValue1.option',
        'productSku.optionValue2.option',
        'productSku.optionValue3.option',
    ];

    /**
     * Toggle is_active on a menu product SKU (shop-side).
     *
     * Wrapper over the setter for the same reason toggleProductForShop is —
     * see the availability block above.
     */
    public function toggleSkuForShop(MenuProductSku $sku, ?MenuAvailabilityActor $actor = null): MenuProductSku
    {
        return $this->setSkuActiveForShop(
            $sku,
            ! $sku->is_active,
            $actor ?? MenuAvailabilityActor::system(),
        );
    }

    /**
     * Override the selling price of a menu product SKU.
     * Sets is_price_overridden to true.
     */
    public function overrideSkuPrice(MenuProductSku $sku, float $sellingPrice): MenuProductSku
    {
        $sku->update([
            'selling_price' => $sellingPrice,
            'is_price_overridden' => true,
        ]);

        return $sku->load(self::SKU_RESPONSE_LOAD);
    }

    /**
     * List branch menus belonging to a single shop branch.
     *
     * @param  array{status?: string, search?: string, per_page?: int}  $filters
     */
    /**
     * Reset a menu product SKU price back to the canonical productSku.selling_price.
     * Queries the live value from product_skus, sets is_price_overridden to false.
     */
    public function resetSkuPrice(MenuProductSku $sku): MenuProductSku
    {
        $masterPrice = $sku->productSku->selling_price;

        $sku->update([
            'selling_price' => $masterPrice,
            'is_price_overridden' => false,
        ]);

        return $sku->load(self::SKU_RESPONSE_LOAD);
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    /**
     * Assert menu is in one of the allowed statuses.
     *
     * @param  MenuStatusEnum[]  $allowedStatuses
     */
    private function assertStatus(Menu $menu, array $allowedStatuses, string $action): void
    {
        $allowedValues = array_map(fn (MenuStatusEnum $s) => $s->value, $allowedStatuses);

        if (! in_array($menu->status, $allowedValues, true)) {
            throw new InvalidStatusTransitionException(
                "Cannot {$action}: menu status is '{$menu->status}', "
                .'expected one of: '.implode(', ', $allowedValues)
            );
        }
    }

    private function getNextPriority(?string $branchId): int
    {
        $query = Menu::query();

        if ($branchId) {
            $query->where('branch_id', $branchId);
        } else {
            $query->whereNull('branch_id');
        }

        $maxPriority = $query->max('priority') ?? 0;

        return $maxPriority + 1;
    }

    private function getNextProductDisplayOrder(Menu $menu): int
    {
        $maxOrder = $menu->menuProducts()->max('display_order') ?? 0;

        return $maxOrder + 1;
    }

    /*
     * ĐÃ GỠ #2537: `backfillMissingMenuProductSkus()` — a one-shot sweep for
     * menu_products missing a SKU row. Its artisan command was removed with the
     * command cleanup, leaving the method with zero callers anywhere in the
     * tree. #2537 closes the gap at the source instead (every ProductSku create
     * now reaches `syncNewSkusToMenuBranches()` via ProductSkuObserver), so
     * there is nothing left for a sweep to find. Do not rebuild it — ruling
     * #2188 forbids new backfill commands.
     */

    /**
     * One-shot migration: activate master menus still parked at Approved.
     */
    public function promoteApprovedMasterMenus(bool $dryRun = false, string $auditSource = 'migrate-master-approved-to-active'): int
    {
        $masters = Menu::query()
            ->where('is_master', true)
            ->where('status', MenuStatusEnum::Approved->value)
            ->whereNull('deleted_at')
            ->get();

        if ($dryRun || $masters->isEmpty()) {
            return $masters->count();
        }

        DB::transaction(function () use ($masters, $auditSource): void {
            foreach ($masters as $menu) {
                $this->activate($menu);
                $menu->logAudit('activated', ['source' => $auditSource]);
            }
        });

        return $masters->count();
    }

    public function propagateNonOverriddenMenuPrice(ProductSku $sku): void
    {
        MenuProductSku::withTrashed()
            ->where('product_sku_id', $sku->id)
            ->where('is_price_overridden', false)
            ->update(['selling_price' => $sku->selling_price]);
    }

    public function deleteMenuProductSkusForProductSku(ProductSku $sku): void
    {
        MenuProductSku::where('product_sku_id', $sku->id)->delete();
    }

    /**
     * Create menu_product_skus for all active ProductSkus of a MenuProduct's product.
     * Uses productSku.selling_price as the initial selling_price.
     */
    private function createSkusForMenuProduct(MenuProduct $menuProduct): void
    {
        $product = $menuProduct->product()->with('skus')->first();

        if (! $product) {
            return;
        }

        foreach ($product->skus->where('is_active', true) as $productSku) {
            $this->restoreOrCreateMenuProductSku($menuProduct, $productSku);
        }
    }

    /**
     * Restore-or-create a menu_product_sku.
     *
     * The (menu_product_id, product_sku_id) unique index does NOT include
     * deleted_at, so a soft-deleted row still occupies the slot. A blind
     * create() therefore collides with SQLSTATE 23000 whenever the row was
     * previously removed (e.g. a branch row whose SKUs were dropped, then
     * re-synced). Restore the trashed row and refresh its state instead —
     * shop price overrides are preserved.
     */
    /**
     * Restore-or-create a menu_product_sku.
     *
     * $activeOnCreate controls the initial state of a SKU that appears on this
     * branch for the FIRST time (brand-new variant, or one re-added at HQ after
     * a delete):
     *   - true  → the FIRST clone of a master menu, where the shop wants a
     *             ready-to-sell menu (cloneToBranch / duplicate paths).
     *   - false → later syncs ("Đồng bộ từ HQ"): "HQ thêm ≠ shop bán ngay",
     *             so a newly-arrived SKU lands INACTIVE and the shop enables it
     *             (same contract as syncNewSkusToMenuBranches).
     *
     * An ALREADY-LIVE branch row always keeps its own is_active — the shop's
     * on/off choice survives every sync, and a row the shop turned off is never
     * silently re-enabled here (Step 1b in syncFromMaster only turns rows OFF).
     */
    private function restoreOrCreateMenuProductSku(MenuProduct $menuProduct, ProductSku $productSku, bool $activeOnCreate = true): void
    {
        $existing = $menuProduct->menuProductSkus()
            ->withTrashed()
            ->where('product_sku_id', $productSku->id)
            ->first();

        if ($existing) {
            if ($existing->trashed()) {
                // Re-add after a delete: reappears with the create-state so a
                // re-added SKU on a synced menu stays off until the shop acts.
                $existing->restore();
                $existing->is_active = $activeOnCreate;
            }
            // A non-trashed existing row keeps its current is_active — never
            // override the shop's on/off choice.

            if (! $existing->is_price_overridden) {
                $existing->selling_price = $productSku->selling_price;
            }

            $existing->save();

            return;
        }

        $menuProduct->menuProductSkus()->create([
            'product_sku_id' => $productSku->id,
            'selling_price' => $productSku->selling_price,
            'is_price_overridden' => false,
            'is_active' => $activeOnCreate,
        ]);
    }

    // =========================================================================
    //  Topping eager-load + schedule filter (Plan 015)
    // =========================================================================

    /**
     * Eager-load chain for surfacing topping_groups on menu products.
     *
     * The chain follows Product → ToppingGroup (M2M with override pivot) →
     * ToppingGroupItem (HasMany) → ToppingGroupItemSku (HasMany), terminating
     * with the per-SKU `productSku` so the frontend can render variant labels.
     *
     * The previous time-of-day / day-of-week schedule filter was removed when
     * `available_days` / `available_from` / `available_to` were dropped from
     * topping_groups (migration 2000_02_13). Re-add the columns and restore
     * the window logic when scheduling comes back (deferred to a later phase
     * per ToppingGroup.yaml notes).
     *
     * @param  string  $prefix  Eager-load prefix the caller is starting from.
     *                          When loading on a MenuProduct collection the
     *                          prefix is `'product'`. When loading from a Menu
     *                          model the prefix is `'menuProducts.product'`.
     * @return array<string, callable(mixed): mixed|string>
     */
    private function toppingEagerLoadChain(Menu $menu, string $prefix = 'product'): array
    {
        // Empty prefix means "load directly on a Product (Eloquent) collection",
        // non-empty prefix means "chain off this path on a parent model" (e.g.
        // `'product'` from listBranchMenuProducts, `'menuProducts.product'`
        // from loadToppingsForMenu prior to the chain-reinit fix). Normalize
        // to a "{prefix-with-trailing-dot}" form so concat doesn't leave a
        // dangling dot when prefix is empty.
        $p = $prefix === '' ? '' : "{$prefix}.";

        return [
            "{$p}toppingGroups" => function ($q): void {
                $q->where('topping_groups.is_active', true)
                    // Tie-break on the pivot's unique id (#2109) — `sort_order`
                    // is not unique and ties would fall back to row order.
                    ->orderBy('product_topping_groups.sort_order')
                    ->orderBy('product_topping_groups.id');
            },
            "{$p}toppingGroups.items" => function ($q): void {
                // SoftDeletes auto-filters trashed rows. is_default still returned
                // (frontend uses it to pre-tick checkboxes).
                //
                // `sort_order` is not unique (#2046) — tie-break on the unique
                // UUIDv7 id so ties can't fall back to physical row order.
                $q->orderBy('sort_order')->orderBy('id');
            },
            "{$p}toppingGroups.items.product:id,name",
            // Plan 015 follow-up — load the topping product's primary
            // gallery file so the POS can render 1:1 thumbnails next to
            // each topping option (mirrors what CustomerMenuService does).
            "{$p}toppingGroups.items.product.galleryFirst",
            "{$p}toppingGroups.items.product.options" => function ($q): void {
                $q->where('is_active', true)->orderBy('position');
            },
            "{$p}toppingGroups.items.product.options.values" => function ($q): void {
                $q->where('is_active', true)->orderBy('position');
            },
            // The topping product's own SKU set, which is what decides whether a
            // wildcard price row still applies (ToppingGroupItem::
            // wildcardPriceApplies → the `applies` flag on each sku row). NOT
            // derivable from `options` above: that relation is filtered to
            // is_active, so a deactivated option axis makes a 2-SKU product look
            // option-less — the exact wrong signal #1277 removed from the
            // customer path. Loaded here so the flag costs no per-row query on a
            // screen that lists dozens of toppings (#1316).
            "{$p}toppingGroups.items.product.skus:id,product_id,is_active",
            "{$p}toppingGroups.items.skus",
            // is_active surfaces the variant's catalog availability so the shop
            // menu badge can show "Inactive" for an HQ-disabled SKU — the same
            // SKU the customer menu hides (CustomerMenuService). Without it the
            // resource falls back to true and the badge disagrees with the
            // customer view.
            "{$p}toppingGroups.items.skus.productSku:id,name,sku,is_active,option_value1_id,option_value2_id,option_value3_id",
            // Option values so MenuToppingGroupItemSkuResource's sku_label can
            // compose "Ít / Nongs" (variant_label) instead of a raw SKU code —
            // product_skus.name is null for option-based variants.
            "{$p}toppingGroups.items.skus.productSku.optionValue1",
            "{$p}toppingGroups.items.skus.productSku.optionValue2",
            "{$p}toppingGroups.items.skus.productSku.optionValue3",
            // Load per-product price/visibility overrides so MenuToppingGroupItemResource
            // can inject the effective extra_price (override_price when set, else HQ base)
            // and is_hidden flag into each MenuToppingGroupItemSkuResource. The where()
            // scope is applied inline here; the product_id resolves from the ToppingGroup
            // → Product join context at eager-load time.
            "{$p}toppingGroups.items.productOverrides",
        ];
    }

    /**
     * Eager-load topping_groups + their nested chain on a Menu instance,
     * applying the timezone-aware schedule filter from the menu's Branch.
     *
     * Used by `MenuController::show` (single-menu read) — paginated list
     * paths build the same chain inline through `listBranchMenuProducts`.
     */
    public function loadToppingsForMenu(Menu $menu): Menu
    {
        $menu->loadMissing('menuProducts.product');

        // Why load on the inner Product collection rather than chain through
        // `menuProducts.product.*`: Laravel's eager-load reinitializes every
        // intermediate relation in a chain, which wipes anything previously
        // loaded on those parents. The shop endpoint loads
        // `menuProducts.menuProductSkus.productSku` BEFORE this method runs;
        // calling `$menu->load(['menuProducts.product.toppingGroups' => ...])`
        // would then drop menuProductSkus, leaving the response with `skus`
        // missing and the FE rendering "no variants" for every product.
        // Loading directly on the gathered Product collection sidesteps the
        // re-init by skipping the `menuProducts` and `product` levels in the
        // chain — toppingGroups attaches to existing Product instances in
        // place.
        $products = new Collection(
            $menu->menuProducts->pluck('product')->filter()->unique('id')->values()->all(),
        );

        if ($products->isNotEmpty()) {
            $products->load($this->toppingEagerLoadChain($menu, ''));
        }

        return $menu;
    }

    // =========================================================================
    //  SKU sync after expand
    // =========================================================================

    /**
     * Sync newly created/restored SKUs into all branch menus that contain
     * this product. Master menus are skipped — they have no MenuProductSku rows.
     *
     * For each (MenuProduct, SKU) pair:
     *   - active row exists  → skip (price/status already set by branch)
     *   - soft-deleted row   → restore (preserves branch price override)
     *   - no row             → create with selling_price=0, is_active=false
     */
    /** @param list<CatalogSkuProjection> $newSkus */
    public function syncNewSkusToMenuBranches(string $productId, array $newSkus): void
    {
        $menuProducts = MenuProduct::where('product_id', $productId)
            ->whereHas('menu', fn ($q) => $q->where('is_master', false))
            ->get();

        foreach ($menuProducts as $menuProduct) {
            foreach ($newSkus as $sku) {
                $existing = MenuProductSku::withTrashed()
                    ->where('menu_product_id', $menuProduct->id)
                    ->where('product_sku_id', $sku->skuId)
                    ->first();

                if ($existing === null) {
                    $menuProduct->menuProductSkus()->create([
                        'product_sku_id' => $sku->skuId,
                        'selling_price' => $sku->sellingPrice,
                        'is_price_overridden' => false,
                        'is_active' => false,
                    ]);
                } elseif ($existing->trashed()) {
                    $existing->restore();
                }
            }
        }
    }
}
