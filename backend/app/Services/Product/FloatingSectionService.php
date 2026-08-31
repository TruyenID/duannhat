<?php

/**
 * FloatingSectionService
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Product;

use App\Exceptions\FloatingSectionOperationException;
use App\Models\FloatingSection;
use App\Models\FloatingSectionProduct;
use App\Models\FloatingSectionProductSku;
use App\Models\FloatingSectionSchedule;
use App\Services\Tax\Contracts\TaxTypeDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FloatingSectionService
{
    /**
     * #962 — loại thuế thuộc Pricing; section nổi chỉ GÁN nhãn, y như
     * {@see MenuService::updateProductTaxType}.
     */
    public function __construct(
        private readonly TaxTypeDirectory $taxTypes,
    ) {}

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{organization_id?: string, brand_id?: string, branch_id?: string|null, master_only?: bool, search?: string, is_active?: bool, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = FloatingSection::query()
            ->withCount('products');

        $query->when($filters['organization_id'] ?? null, fn ($q, $id) => $q->where('organization_id', $id));
        $query->when($filters['brand_id'] ?? null, fn ($q, $id) => $q->where('brand_id', $id));
        // HQ manages the master catalog only — branch clones are managed from
        // the shop side. Mutually exclusive with branch_id (shop listing).
        $query->when($filters['master_only'] ?? false, fn ($q) => $q->whereNull('branch_id'));
        $query->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id));
        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));
        $query->when($filters['search'] ?? null, fn ($q, $search) => $q->where('name', 'like', "%{$search}%"));

        return $query
            // Active sections surface first regardless of filter — sorting
            // by status keeps a mixed Active/Inactive list scannable.
            ->orderByDesc('is_active')
            ->orderBy('priority')
            // Most-recently-created first when priority ties (the common
            // case — create() defaults every new section to priority 0, see
            // below) — mirrors Menu's list default (`-updated_at`) so a
            // freshly created section surfaces at the top instead of sinking
            // to the bottom behind older same-priority rows.
            ->orderByDesc('created_at')
            ->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): FloatingSection
    {
        $section = FloatingSection::with($this->productDetailEagerLoad())
            ->withCount('products')
            ->findOrFail($id);

        // Floating section is "a menu thu nhỏ" — it carries the same topping
        // groups as the menu. Load them the same way MenuService does so the
        // shop detail can render + override toppings (3-tier resolution).
        $this->loadToppingsForSection($section);

        return $section;
    }

    /**
     * Eager-load topping_groups + their nested chain onto the section's
     * embedded catalog products, mirroring MenuService::loadToppingsForMenu.
     *
     * Loads on the gathered Product collection rather than chaining through
     * `products.product.toppingGroups` because Laravel re-initializes every
     * intermediate relation in a chain — that would wipe the `products.skus`
     * / `products.product.skus` already loaded by productDetailEagerLoad() and
     * leave the response with no variants. Attaching directly to the existing
     * Product instances sidesteps the re-init.
     */
    public function loadToppingsForSection(FloatingSection $section): FloatingSection
    {
        $section->loadMissing('products.product');

        $products = new Collection(
            $section->products->pluck('product')->filter()->unique('id')->values()->all(),
        );

        if ($products->isNotEmpty()) {
            $products->load($this->toppingEagerLoadChain(''));
        }

        return $section;
    }

    /**
     * Topping eager-load chain — same shape as MenuService::toppingEagerLoadChain.
     * Empty prefix = load directly on a Product collection.
     *
     * @return array<int|string, mixed>
     */
    private function toppingEagerLoadChain(string $prefix = 'product'): array
    {
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
                // `sort_order` is not unique (#2046) — tie-break on the unique
                // UUIDv7 id so ties can't fall back to physical row order.
                $q->orderBy('sort_order')->orderBy('id');
            },
            "{$p}toppingGroups.items.product:id,name",
            "{$p}toppingGroups.items.product.galleryFirst",
            "{$p}toppingGroups.items.product.options" => function ($q): void {
                $q->where('is_active', true)->orderBy('position');
            },
            "{$p}toppingGroups.items.product.options.values" => function ($q): void {
                $q->where('is_active', true)->orderBy('position');
            },
            // Same reason as the menu twin (MenuService::toppingEagerLoadChain):
            // the `applies` flag on a wildcard price row needs the topping
            // product's SKU set, and `options` cannot stand in for it because it
            // is filtered to is_active (#1316).
            "{$p}toppingGroups.items.product.skus:id,product_id,is_active",
            "{$p}toppingGroups.items.skus",
            "{$p}toppingGroups.items.skus.productSku:id,name,sku,is_active,option_value1_id,option_value2_id,option_value3_id",
            "{$p}toppingGroups.items.skus.productSku.optionValue1",
            "{$p}toppingGroups.items.skus.productSku.optionValue2",
            "{$p}toppingGroups.items.skus.productSku.optionValue3",
            // HQ per-product overrides (tier-2) so the resource can inject the
            // effective extra_price + is_hidden when no shop override exists.
            "{$p}toppingGroups.items.productOverrides",
        ];
    }

    /**
     * Shared eager-load shape for `products` + their embedded catalog
     * product / per-SKU visibility rows — used by findById() and by
     * cloneToBranch()/syncFromMaster()'s return value, so the response
     * right after a clone/sync carries the same image/variant detail as a
     * subsequent GET would (including each SKU's own thumbnail).
     *
     * @return array<string, mixed>
     */
    private function productDetailEagerLoad(): array
    {
        return [
            'schedules' => fn ($q) => $q->orderBy('priority')->orderBy('created_at'),
            // #3170 — unique-id tie-break; display_order alone is a partial
            // order and leaves tied rows to the query plan.
            'products' => fn ($q) => $q->orderBy('display_order')
                ->orderBy('floating_section_products.id'),
            // Mirrors ProductService::findById's eager-load shape so the
            // embedded `product` carries the same image_url/active_skus_count
            // the HQ/shop UIs read for the catalog thumbnail + variant count.
            // `skus` (active only) is also loaded so a multi-variant product
            // can expand its variant list in the products table.
            'products.product' => fn ($q) => $q->with([
                'galleryFirst',
                'skus' => fn ($q2) => $q2->where('is_active', true)
                    ->with(['optionValue1', 'optionValue2', 'optionValue3'])
                    ->orderBy('created_at'),
            ])
                ->withCount(['skus as active_skus_count' => fn ($q2) => $q2->where('is_active', true)]),
            // Per-SKU visibility rows — only present on branch clones (see
            // FloatingSectionProductSku.yaml). Master rows simply have none.
            'products.skus.productSku' => fn ($q) => $q->with([
                'optionValue1', 'optionValue2', 'optionValue3', 'galleryFirst',
            ]),
            // Tier-1 shop topping overrides — stamped onto the embedded product
            // by FloatingSectionProductResource so ProductResource →
            // MenuToppingGroupItemResource can apply the 3-tier priority.
            'products.toppingOverrides',
        ];
    }

    // =========================================================================
    //  Create / Update / Delete
    // =========================================================================

    public function create(array $data): FloatingSection
    {
        $data = $this->normalizeTranslations($data);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['priority'] = $data['priority'] ?? 0;

        $section = FloatingSection::create($data);

        return $section->load(['schedules', 'products'])->loadCount('products');
    }

    public function update(FloatingSection $floatingSection, array $data): FloatingSection
    {
        $data = $this->normalizeTranslations($data);
        $floatingSection->update($data);

        return $floatingSection->load(['schedules', 'products'])->loadCount('products');
    }

    /**
     * Set (or clear) the per-item tax-type override on a floating-section
     * product — parity with MenuService::updateProductTaxType. Null = inherit
     * from the product. The tax type must be active and belong to the brand.
     */
    public function updateProductTaxType(FloatingSectionProduct $sectionProduct, ?string $taxTypeId, ?string $brandId = null): FloatingSectionProduct
    {
        if ($taxTypeId !== null) {
            $type = $this->taxTypes->findAssignable($taxTypeId, $brandId);

            if ($type === null) {
                throw ValidationException::withMessages([
                    'tax_type_id' => 'The selected tax_type_id is invalid.',
                ]);
            }
        }

        $sectionProduct->update(['tax_type_id' => $taxTypeId]);

        return $sectionProduct->fresh();
    }

    /**
     * Reshape the locale-keyed request payload ({ja: {name}, en: {name},
     * vi: {name}}) into Astrotomic's `field:locale` mass-assignment syntax
     * (name:ja, name:en, name:vi) and drop the locale wrappers. Mirrors
     * MenuService::normalizeTranslations. `name` is the only translatable
     * field on FloatingSection; the top-level `name` mirror is left untouched.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTranslations(array $data): array
    {
        foreach (['ja', 'en', 'vi'] as $locale) {
            if (array_key_exists('name', $data[$locale] ?? [])) {
                $data["name:{$locale}"] = $data[$locale]['name'];
            }
            unset($data[$locale]);
        }

        return $data;
    }

    /**
     * Duplicate a master floating section into a new, independent master
     * copy — schedules + products + per-SKU pricing all deep-copied, no
     * master_section_id link back to the source (mirrors
     * MenuService::duplicate()). Lets HQ quickly spin up a variant of an
     * existing promo instead of rebuilding from scratch.
     */
    public function duplicate(FloatingSection $section): FloatingSection
    {
        if ($section->branch_id !== null) {
            throw new FloatingSectionOperationException('Only a master (HQ) floating section can be duplicated.');
        }

        return DB::transaction(function () use ($section) {
            $section->load(['schedules', 'products.skus']);

            $copy = FloatingSection::create([
                'organization_id' => $section->organization_id,
                'brand_id' => $section->brand_id,
                'branch_id' => null,
                'master_section_id' => null,
                'name' => "{$section->name} (Copy)",
                'is_active' => true,
                'priority' => $this->getNextMasterPriority($section->brand_id),
                'start_date' => $section->start_date,
                'end_date' => $section->end_date,
            ]);

            foreach ($section->schedules as $schedule) {
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

            foreach ($section->products as $product) {
                $newProduct = $copy->products()->create([
                    'product_id' => $product->product_id,
                    // #1233 — the per-item tax tier is read at pricing time
                    // (CustomerMenuService collapses it into the customer +
                    // workstation feed), so leaving it out re-rates the copy.
                    'tax_type_id' => $product->tax_type_id,
                    'is_active' => $product->is_active,
                    'display_order' => $product->display_order,
                ]);

                foreach ($product->skus as $sourceSku) {
                    $newProduct->skus()->create([
                        'product_sku_id' => $sourceSku->product_sku_id,
                        'is_active' => $sourceSku->is_active,
                        'selling_price' => $sourceSku->selling_price,
                        'is_price_overridden' => $sourceSku->is_price_overridden,
                    ]);
                }
            }

            return $copy->load($this->productDetailEagerLoad())->loadCount('products');
        });
    }

    private function getNextMasterPriority(string $brandId): int
    {
        $maxPriority = FloatingSection::query()
            ->where('brand_id', $brandId)
            ->whereNull('branch_id')
            ->max('priority') ?? 0;

        return $maxPriority + 1;
    }

    public function delete(FloatingSection $floatingSection): bool
    {
        return DB::transaction(function () use ($floatingSection) {
            $floatingSection->products()->delete();

            return $floatingSection->delete();
        });
    }

    // =========================================================================
    //  Master → Branch clone (mirrors MenuService::cloneToBranch, minus the
    //  ongoing sync-from-master — a floating section clone is a one-time,
    //  fully independent copy the shop then edits on its own).
    // =========================================================================

    public function cloneToBranch(FloatingSection $masterSection, string $branchId): FloatingSection
    {
        if ($masterSection->branch_id !== null) {
            throw new FloatingSectionOperationException('Only a master (HQ) floating section can be cloned to a branch.');
        }

        // withTrashed: there is no DB unique on (master_section_id, branch_id),
        // so a soft-deleted prior clone would otherwise slip past this guard
        // and let a second live clone be created for the same branch. Block on
        // a trashed clone too — HQ must restore it rather than spawn a duplicate.
        $existingClone = FloatingSection::withTrashed()
            ->where('master_section_id', $masterSection->id)
            ->where('branch_id', $branchId)
            ->first();

        if ($existingClone) {
            throw new FloatingSectionOperationException('This floating section has already been cloned to this branch.');
        }

        return DB::transaction(function () use ($masterSection, $branchId) {
            $masterSection->load(['schedules', 'products.skus']);

            $branchSection = FloatingSection::create([
                'organization_id' => $masterSection->organization_id,
                'brand_id' => $masterSection->brand_id,
                'branch_id' => $branchId,
                'master_section_id' => $masterSection->id,
                'name' => $masterSection->name,
                'is_active' => true,
                'priority' => $masterSection->priority,
                'start_date' => $masterSection->start_date,
                'end_date' => $masterSection->end_date,
            ]);

            foreach ($masterSection->schedules as $schedule) {
                $branchSection->schedules()->create([
                    'master_schedule_id' => $schedule->id,
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

            foreach ($masterSection->products as $product) {
                $branchProduct = $branchSection->products()->create([
                    'product_id' => $product->product_id,
                    // #1233 — mirrors the master like placement does. Customers
                    // are served the BRANCH section, never the master, so a tier
                    // that stops at HQ never reaches a bill.
                    'tax_type_id' => $product->tax_type_id,
                    'is_active' => $product->is_active,
                    'display_order' => $product->display_order,
                ]);

                // Copy the master's own per-SKU rows 1:1 — this carries over
                // any promo price HQ already set, not just the base catalog
                // price (the master always has these rows too, see
                // FloatingSectionProductSku.yaml / createSkusForProduct()).
                foreach ($product->skus as $masterSku) {
                    $branchProduct->skus()->create([
                        'product_sku_id' => $masterSku->product_sku_id,
                        'is_active' => $masterSku->is_active,
                        'selling_price' => $masterSku->selling_price,
                        'is_price_overridden' => $masterSku->is_price_overridden,
                    ]);
                }
            }

            return $branchSection->load($this->productDetailEagerLoad())->loadCount('products');
        });
    }

    /**
     * #1233 — mirror ONLY the per-item tax tier from the master onto a branch
     * clone, for sections cloned/duplicated/synced BEFORE 88fcc12f taught those
     * paths to carry `tax_type_id`. Customers are served the BRANCH section, so
     * a tier that stops at HQ never reaches a bill; and nothing re-syncs a
     * floating section on its own (no scheduler, only a button), so those rows
     * stay wrong until something writes them.
     *
     * Deliberately NOT syncFromMaster, even though that now mirrors tax
     * correctly: sync also creates missing branch rows, soft-deletes rows whose
     * master product disappeared, restores trashed ones, tops up SKU sets and
     * re-applies display_order. Those are real layout mutations an operator
     * running a "tax backfill" did not ask for and would not think to review.
     * This copies ONE column and nothing else.
     *
     * MIRROR, not fill-blanks: it writes the master's value even when that
     * value is NULL, because a rate HQ has retired must clear at every branch.
     * The only route that edits a floating-section tax type lives under /hq/,
     * so the shop has no value of its own to protect — the same contract
     * syncProductsFromMaster() uses above.
     *
     * @return array{item: int} count of branch product rows whose tax differs
     *                          from the master (written only when $apply)
     */
    public function mirrorTaxFromMaster(FloatingSection $branchSection, bool $apply = true): array
    {
        $master = $branchSection->masterSection;

        if ($master === null) {
            return ['item' => 0];
        }

        $masterTaxByProduct = FloatingSectionProduct::query()
            ->where('floating_section_id', $master->id)
            ->pluck('tax_type_id', 'product_id')
            ->all();

        // Trashed branch rows are excluded: a soft-deleted row is not on any
        // bill, and touching it would be the layout change this command refuses
        // to make. A restore goes through sync, which mirrors tax itself.
        $branchProducts = FloatingSectionProduct::query()
            ->where('floating_section_id', $branchSection->id)
            ->get(['id', 'product_id', 'tax_type_id']);

        $updates = [];

        foreach ($branchProducts as $branchProduct) {
            // A product present only on the branch has no master value to
            // mirror; leaving it alone is correct, not an oversight.
            if (! array_key_exists($branchProduct->product_id, $masterTaxByProduct)) {
                continue;
            }
            if ($branchProduct->tax_type_id !== $masterTaxByProduct[$branchProduct->product_id]) {
                $updates[$branchProduct->id] = $masterTaxByProduct[$branchProduct->product_id];
            }
        }

        $changed = ['item' => count($updates)];

        if (! $apply || $updates === []) {
            return $changed;
        }

        DB::transaction(function () use ($updates): void {
            foreach ($updates as $id => $taxTypeId) {
                FloatingSectionProduct::whereKey($id)->update(['tax_type_id' => $taxTypeId]);
            }
        });

        return $changed;
    }

    /**
     * Pull the latest schedule windows + product set from the HQ master into
     * a branch clone. Mirrors MenuService::syncFromMaster's core rule: HQ
     * layout/timing changes propagate down, but anything the shop itself
     * chose — is_active on a product or schedule, a price override — is
     * never touched by a sync.
     *
     * Products match on product_id (a floating section is flat and unique
     * on [floating_section_id, product_id], so no relink bookkeeping is
     * needed the way Menu's cross-section moves require). Schedules match
     * on master_schedule_id, populated by cloneToBranch(); branch schedule
     * rows with no master link (added directly on the clone, or cloned
     * before this field was wired up) are left alone — never auto-deleted.
     */
    public function syncFromMaster(FloatingSection $branchSection): FloatingSection
    {
        if (! $branchSection->master_section_id) {
            throw new FloatingSectionOperationException('This floating section is not cloned from a master section.');
        }

        return DB::transaction(function () use ($branchSection) {
            $masterSection = FloatingSection::with(['schedules', 'products.skus', 'products.product.skus'])
                ->findOrFail($branchSection->master_section_id);

            // Top up the master's own SKU set first — a variant added to the
            // catalog after this product joined the section won't have a
            // master row yet otherwise, and the branch has nothing to copy.
            $masterSection->products->each(fn (FloatingSectionProduct $p) => $this->topUpSkusForProduct($p));
            $masterSection->load('products.skus');

            $this->syncProductsFromMaster($branchSection, $masterSection);
            $this->syncSchedulesFromMaster($branchSection, $masterSection);

            return $branchSection->fresh($this->productDetailEagerLoad())->loadCount('products');
        });
    }

    private function syncProductsFromMaster(FloatingSection $branchSection, FloatingSection $masterSection): void
    {
        $masterProducts = $masterSection->products;
        $masterProductIds = $masterProducts->pluck('product_id');

        $branchByProductId = $branchSection->products()->withTrashed()->get()->keyBy('product_id');

        foreach ($masterProducts as $masterProduct) {
            $branchProduct = $branchByProductId->get($masterProduct->product_id);

            if ($branchProduct === null) {
                // "HQ thêm ≠ shop bán ngay": a product that arrives on this
                // floating section via SYNC lands INACTIVE, and the shop
                // enables it when ready. (The first clone — cloneToBranch —
                // keeps the master's active state so a cloned section is
                // ready to sell; only this sync path defers to the shop.)
                $branchProduct = $branchSection->products()->create([
                    'product_id' => $masterProduct->product_id,
                    // #1233 — tax mirrors the master even though is_active
                    // deliberately does not: the rate is HQ's call, the
                    // decision to sell is the shop's.
                    'tax_type_id' => $masterProduct->tax_type_id,
                    'is_active' => false,
                    'display_order' => $masterProduct->display_order,
                ]);
            } else {
                if ($branchProduct->trashed()) {
                    $branchProduct->restore();
                }

                // is_active is the shop's own — the master's ordering and tax
                // follow down. Tax is a MIRROR (#1233, same contract as menus
                // in #1227): the shop has no tax editor, so writing the
                // master's value even when it is NULL is the only way a rate
                // retired at HQ ever leaves the shops.
                $branchProduct->update([
                    'display_order' => $masterProduct->display_order,
                    'tax_type_id' => $masterProduct->tax_type_id,
                ]);
            }

            $this->syncProductSkusFromMaster($branchProduct, $masterProduct);
        }

        $branchSection->products()
            ->whereNotIn('product_id', $masterProductIds)
            ->get()
            ->each(fn (FloatingSectionProduct $orphan) => $orphan->delete());
    }

    /**
     * Add any SKU row that exists on the master product but not yet on the
     * branch's copy (a newly-appeared variant). It lands INACTIVE (sync
     * contract — the shop enables it), carrying over the master's CURRENT
     * price/override state as the branch's starting point. Existing branch SKU
     * rows (and their shop-set is_active/price) are never touched. Finally,
     * variants disabled at HQ are mirrored off.
     */
    private function syncProductSkusFromMaster(FloatingSectionProduct $branchProduct, FloatingSectionProduct $masterProduct): void
    {
        $existingSkuIds = $branchProduct->skus()->withTrashed()->pluck('product_sku_id');

        foreach ($masterProduct->skus as $masterSku) {
            if ($existingSkuIds->contains($masterSku->product_sku_id)) {
                continue;
            }

            // A newly-appeared variant arriving via SYNC lands INACTIVE so the
            // shop enables it deliberately ("HQ thêm ≠ shop bán ngay"). Price /
            // override state still carries over from the master as the shop's
            // starting point. (cloneToBranch keeps the active state instead.)
            $branchProduct->skus()->create([
                'product_sku_id' => $masterSku->product_sku_id,
                'is_active' => false,
                'selling_price' => $masterSku->selling_price,
                'is_price_overridden' => $masterSku->is_price_overridden,
            ]);
        }

        // Mirror the HQ "variant off" state: an active branch SKU pointing at a
        // ProductSku that has since been disabled at HQ can never be sold, so
        // deactivate it on sync (same as MenuService::syncFromMaster Step 1b).
        // We only turn rows OFF and never touch selling_price /
        // is_price_overridden, so the shop's price override is preserved.
        $branchProduct->skus()
            ->where('is_active', true)
            ->whereHas('productSku', fn ($q) => $q->where('is_active', false))
            ->update(['is_active' => false]);
    }

    private function syncSchedulesFromMaster(FloatingSection $branchSection, FloatingSection $masterSection): void
    {
        $masterSchedules = $masterSection->schedules;
        $masterScheduleIds = $masterSchedules->pluck('id');

        $branchByMasterId = $branchSection->schedules()->withTrashed()->get()
            ->filter(fn (FloatingSectionSchedule $s) => $s->master_schedule_id !== null)
            ->keyBy('master_schedule_id');

        foreach ($masterSchedules as $masterSchedule) {
            $branchSchedule = $branchByMasterId->get($masterSchedule->id);

            if ($branchSchedule === null) {
                $branchSection->schedules()->create([
                    'master_schedule_id' => $masterSchedule->id,
                    'start_time' => $masterSchedule->getRawOriginal('start_time'),
                    'end_time' => $masterSchedule->getRawOriginal('end_time'),
                    'start_date' => $masterSchedule->start_date,
                    'end_date' => $masterSchedule->end_date,
                    'days_of_week' => $masterSchedule->days_of_week,
                    'is_active' => $masterSchedule->is_active,
                    'priority' => $masterSchedule->priority,
                    'created_by_id' => auth()->id(),
                ]);

                continue;
            }

            if ($branchSchedule->trashed()) {
                $branchSchedule->restore();
            }

            // is_active is the shop's own. The time window normally follows
            // the master too — that's the whole point of sync — but once
            // the shop has explicitly overridden it (see
            // FloatingSectionScheduleService::overrideTime), sync stops
            // touching start_time/end_time/days_of_week so the override
            // survives, exactly like a price override survives a product
            // sync.
            $branchSchedule->update([
                'start_date' => $masterSchedule->start_date,
                'end_date' => $masterSchedule->end_date,
                'priority' => $masterSchedule->priority,
                ...($branchSchedule->is_time_overridden ? [] : [
                    'start_time' => $masterSchedule->getRawOriginal('start_time'),
                    'end_time' => $masterSchedule->getRawOriginal('end_time'),
                    'days_of_week' => $masterSchedule->days_of_week,
                ]),
            ]);
        }

        // Only remove branch rows that are linked to a master schedule which
        // has genuinely disappeared — unlinked (master_schedule_id null)
        // rows are the shop's own and are never touched here.
        $branchSection->schedules()
            ->whereNotNull('master_schedule_id')
            ->whereNotIn('master_schedule_id', $masterScheduleIds)
            ->get()
            ->each(fn (FloatingSectionSchedule $orphan) => $orphan->delete());
    }

    // =========================================================================
    //  Products
    // =========================================================================

    /**
     * @param  array<int, string>  $productIds
     * @return Collection<int, FloatingSectionProduct>
     */
    public function addProducts(FloatingSection $floatingSection, array $productIds): Collection
    {
        $nextOrder = ($floatingSection->products()->max('display_order') ?? 0) + 1;
        $created = new Collection;

        foreach ($productIds as $productId) {
            // withTrashed so a product removed earlier and re-added doesn't
            // try to INSERT a second row and hit the unique constraint on
            // (floating_section_id, product_id) — soft-deleted rows still
            // occupy that slot. Restore it instead, same as
            // syncProductsFromMaster()'s relink step.
            $existing = $floatingSection->products()
                ->withTrashed()
                ->where('product_id', $productId)
                ->first();

            if ($existing !== null) {
                if ($existing->trashed()) {
                    $existing->restore();
                    $existing->update([
                        'is_active' => true,
                        'display_order' => $nextOrder++,
                    ]);
                    $created->push($existing);
                }

                // Re-adding an existing product is the explicit, idempotent
                // action that imports catalog variants added since the first
                // add. Reads must never mutate the database.
                $this->topUpSkusForProduct($existing);

                continue;
            }

            $product = $floatingSection->products()->create([
                'product_id' => $productId,
                'is_active' => true,
                'display_order' => $nextOrder++,
            ]);

            $this->topUpSkusForProduct($product);
            $created->push($product);
        }

        return $created->load(['product', 'skus.productSku']);
    }

    /**
     * Idempotently add a FloatingSectionProductSku row for any active
     * variant the base Product carries that this row doesn't have yet —
     * price copied from the base ProductSku. Called both right after
     * addProducts() creates/re-adds the row and from explicit master sync.
     * Never touches an
     * existing row (HQ/shop pricing + is_active survive).
     *
     * Present on BOTH master and branch rows — a floating section's whole
     * purpose is an HQ-defined promo price, unlike MenuProductSku which
     * only ever exists on branch clones.
     */
    private function topUpSkusForProduct(FloatingSectionProduct $product): void
    {
        $product->loadMissing('product.skus');

        if ($product->product === null) {
            return;
        }

        $now = now();
        $rows = $product->product->skus
            ->where('is_active', true)
            ->map(fn ($productSku) => [
                'id' => (string) Str::uuid(),
                'floating_section_product_id' => $product->id,
                'product_sku_id' => $productSku->id,
                'is_active' => true,
                'selling_price' => $productSku->selling_price,
                'is_price_overridden' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

        if ($rows !== []) {
            // Atomic under concurrent add/sync requests. The composite unique
            // key makes duplicates impossible; existing price/availability
            // overrides are deliberately preserved.
            DB::table('floating_section_product_skus')->insertOrIgnore($rows);
        }

        $product->unsetRelation('skus');
    }

    public function removeProduct(FloatingSectionProduct $product): bool
    {
        return $product->delete();
    }

    public function toggleProduct(FloatingSectionProduct $product): FloatingSectionProduct
    {
        $product->update(['is_active' => ! $product->is_active]);

        return $product;
    }

    /**
     * Toggle is_active on a single SKU (e.g. one size temporarily out of
     * stock, or HQ pausing a variant on the master before it ever reaches a
     * branch). Present on both master and branch rows.
     */
    public function toggleProductSku(FloatingSectionProductSku $sku): FloatingSectionProductSku
    {
        $sku->update(['is_active' => ! $sku->is_active]);

        return $sku->load('productSku');
    }

    /**
     * Override a single SKU's promotional price. Mirrors
     * MenuService::overrideSkuPrice — HQ uses this on the master row, shop
     * on its own branch clone row.
     */
    public function overrideSkuPrice(FloatingSectionProductSku $sku, float $sellingPrice): FloatingSectionProductSku
    {
        $sku->update([
            'selling_price' => $sellingPrice,
            'is_price_overridden' => true,
        ]);

        return $sku->load('productSku');
    }

    /**
     * Reset a SKU's price back to the base ProductSku's current price.
     * Mirrors MenuService::resetSkuPrice.
     */
    public function resetSkuPrice(FloatingSectionProductSku $sku): FloatingSectionProductSku
    {
        $sku->loadMissing('productSku');
        // Null-guard: the base ProductSku uses SoftDeletes and the relation is
        // a plain belongsTo (default scope hides trashed), so a soft-deleted
        // base SKU resolves to null → keep the current price instead of a 500,
        // just clear the override flag. Mirrors resetTimeOverride's fallback.
        $defaultPrice = $sku->productSku?->selling_price ?? $sku->selling_price;

        $sku->update([
            'selling_price' => $defaultPrice,
            'is_price_overridden' => false,
        ]);

        return $sku->load('productSku');
    }

    /**
     * Reorder products using the same 2-phase UPDATE strategy as
     * MenuService::reorderProducts()-adjacent helpers, avoiding unique
     * constraint collisions on (floating_section_id, display_order) if one
     * is ever added.
     *
     * @param  array<int, string>  $orderedIds
     */
    public function reorderProducts(FloatingSection $floatingSection, array $orderedIds): FloatingSection
    {
        if (count($orderedIds) !== count(array_unique($orderedIds))) {
            throw ValidationException::withMessages([
                'ordered_ids' => ['ordered_ids must not contain duplicate IDs.'],
            ]);
        }

        $total = $floatingSection->products()->count();
        $matched = $floatingSection->products()->whereIn('id', $orderedIds)->count();

        if ($matched !== count($orderedIds)) {
            throw ValidationException::withMessages([
                'ordered_ids' => ['One or more product IDs do not belong to this floating section.'],
            ]);
        }

        if ($total !== count($orderedIds)) {
            throw ValidationException::withMessages([
                'ordered_ids' => ["ordered_ids must cover all products. Expected {$total} IDs, received ".count($orderedIds).'.'],
            ]);
        }

        DB::transaction(function () use ($floatingSection, $orderedIds) {
            foreach ($orderedIds as $index => $id) {
                $floatingSection->products()
                    ->where('id', $id)
                    ->update(['display_order' => $index + 1]);
            }
        });

        return $floatingSection->load(['products' => fn ($q) => $q->orderBy('display_order')
            // #3170 — rows outside $orderedIds keep their old, possibly tied,
            // display_order, so the reorder response needs the tie-break too.
            ->orderBy('floating_section_products.id')]);
    }
}
