<?php

namespace App\Services\Tax;

use App\Exceptions\TaxTypeInUseException;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\TaxType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TaxTypeService — hand-written standalone service for the brand-scoped
 * TaxType entity (plan-043). Mirrors App\Services\Product\ProductTypeService.
 *
 * Enforces the "single default per brand" invariant (Decision 9) and the
 * "deactivate over delete" guard (Decision 5): a type still referenced by a
 * product, a menu-product override, or a branch default cannot be deleted.
 */
class TaxTypeService
{
    /**
     * The three Japanese-standard consumption-tax types every brand must carry
     * (軽減税率 model, plan-043 T6.4): 標準 10/10 (default) · 軽減 10/8 · 非課税
     * 0/0. Direction is the legal standard — eat-in 10% / takeaway 8%.
     *
     * @var array<int, array{code: string, ja: string, en: string, vi: string, dine_in: int, takeaway: int, default: bool}>
     */
    public const STANDARD_TYPES = [
        ['code' => 'STANDARD', 'ja' => '標準税率', 'en' => 'Standard', 'vi' => 'Thuế chuẩn', 'rate' => 10, 'default' => true],
        ['code' => 'REDUCED', 'ja' => '軽減税率', 'en' => 'Reduced', 'vi' => 'Thuế giảm', 'rate' => 8, 'default' => false],
        ['code' => 'EXEMPT', 'ja' => '非課税', 'en' => 'Exempt', 'vi' => 'Miễn thuế', 'rate' => 0, 'default' => false],
    ];

    /**
     * Ensure a brand carries the three standard tax types (idempotent by
     * (brand_id, code) — existing codes are left untouched, so re-firing never
     * duplicates or clobbers an admin's rate edits).
     *
     * AUDIT FIX 1.2 (2026-07-14): a brand created post-plan-043 got ZERO tax
     * types, so every order for the new brand resolved to 0% silently.
     *
     * #2320 — đây là bản cài đặt DUY NHẤT của bộ ba loại thuế chuẩn. Trước đó
     * còn hai bản nữa: `TaxTypeSeeder` (bọc hàm này) và `JapaneseTaxSeeder`
     * (bản chép tay, ghi thẳng `DB::table('tax_types')->upsert()` với UUIDv5
     * tất định, và XOÁ hàng do hàm này tạo để chiếm chỗ). Vì ghi thẳng nên nó
     * cũng bỏ qua {@see self::ensureOpenRatePeriod()} — loại thuế sinh ra không
     * có kỳ hiệu lực nào (#2318). Cả hai đã bị gỡ.
     *
     * Người gọi: `BrandBaselineProvisioner` — và chỉ nó. Đường vào là Platform sync, tạo chi nhánh,
     * `BaselineProvisioningSeeder`, `php artisan provisioning:reconcile`.
     * KHÔNG gắn vào hook `Brand::created`: hàng trăm test tạo brand qua factory
     * rồi tự seed TaxType, unique [brand_id, code] sẽ đụng.
     *
     * @return int number of types created (0 when the brand already had all codes)
     */
    public function ensureStandardTypesForBrand(Brand $brand, ?string $organizationId = null): int
    {
        $organizationId ??= $this->resolveOrganizationIdForBrand($brand);
        if ($organizationId === null) {
            return 0;
        }

        $existing = TaxType::query()
            ->where('brand_id', $brand->id)
            ->pluck('code')
            ->all();

        $created = 0;
        foreach (self::STANDARD_TYPES as $type) {
            if (in_array($type['code'], $existing, true)) {
                continue;
            }

            $this->create([
                'code' => $type['code'],
                // Astrotomic locale-keyed shape (proven in BackfillTaxTypes).
                'ja' => ['name' => $type['ja']],
                'en' => ['name' => $type['en']],
                'vi' => ['name' => $type['vi']],
                'rate' => $type['rate'],
                // Never steal the default from a brand that already has one
                // (partial seed / admin re-pointed the default).
                'is_default' => $type['default'] && ! TaxType::query()
                    ->where('brand_id', $brand->id)->where('is_default', true)->exists(),
                'is_active' => true,
                'organization_id' => $organizationId,
                'brand_id' => $brand->id,
            ]);
            $created++;
        }

        // Round-3 audit B6 (2026-07-14): a partially-seeded brand could end
        // this sweep with types but ZERO defaults (e.g. STANDARD pre-existed
        // WITHOUT is_default, so the skip above never assigns one) — leaving
        // TaxResolver's brand-default tier empty. Promote STANDARD (falling
        // back to any active type) so the invariant "a provisioned brand has
        // exactly one default" always holds.
        $hasDefault = TaxType::query()
            ->where('brand_id', $brand->id)->where('is_default', true)->exists();
        if (! $hasDefault) {
            $candidate = TaxType::query()
                ->where('brand_id', $brand->id)->where('is_active', true)
                ->orderByRaw("CASE WHEN code = 'STANDARD' THEN 0 ELSE 1 END")
                ->first();
            if ($candidate !== null) {
                $this->update($candidate, ['is_default' => true]);
            }
        }

        $this->ensureRatePeriodsForBrand($brand->id);

        return $created;
    }

    /**
     * Resolve the organization id a brand's tax types should belong to: the
     * org of one of its products (authoritative link), falling back to the
     * Organization matching the brand's console org id.
     */
    private function resolveOrganizationIdForBrand(Brand $brand): ?string
    {
        return Product::query()->where('brand_id', $brand->id)->value('organization_id')
            ?? Organization::query()
                ->where('console_organization_id', $brand->console_organization_id)
                ->value('id');
    }

    /**
     * @param  array{organization_id: string, brand_id?: string|null, search?: string, is_active?: bool, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = TaxType::query()
            ->withCount('products')
            ->with('translations');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        // Brand scope — set by HQ controllers from the {brandSlug} route param
        // via the ResolveBrandFromSlug middleware.
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->whereTranslationLike('name', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        });

        $query->when(isset($filters['is_active']), fn ($q) => $q->where('is_active', $filters['is_active']));

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): TaxType
    {
        return TaxType::withCount('products')->with('translations')->findOrFail($id);
    }

    /**
     * Create a tax type. When `is_default` is true, atomically clears the
     * previous brand default first — exactly one default per brand.
     */
    public function create(array $data): TaxType
    {
        // Apply schema defaults so the persisted row (and the resource) always
        // carries an explicit boolean, never null (mirrors TaxTypeServiceBase).
        $data['is_default'] = $data['is_default'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;

        return DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                $this->clearBrandDefault($data['brand_id']);
            }

            $taxType = TaxType::create($data);

            // plan-043 BUG-9 — persist the Astrotomic translation sidecar rows
            // explicitly. TaxType::create stages them in memory but relies on
            // the model `saved` event to write them; DatabaseSeeder runs under
            // `WithoutModelEvents`, which mutes that event → the seeded types
            // come out nameless. flushTranslations() stamps the FK by hand so
            // the rows land regardless of the event state (mirrors the
            // generated TaxTypeServiceBase — this standalone service dropped it).
            $this->flushTranslations($taxType);

            $this->ensureOpenRatePeriod($taxType);

            return $taxType->loadCount('products')->load('translations');
        });
    }

    /**
     * Đảm bảo MỌI tax type của một brand có kỳ hiệu lực đang mở.
     *
     * #2332 thêm hàm này vì `JapaneseTaxSeeder::provisionTaxTypes` ghi thẳng
     * `DB::table('tax_types')` để giữ id tất định, nên không đi qua `create()`
     * và không bao giờ mở kỳ hiệu lực — đo được bằng `migrate:fresh --seed`:
     * tax_types 3, tax_type_rates 0.
     *
     * #2320 gỡ hẳn seeder đó (nó là bản cài đặt thứ ba của cùng một việc), nên
     * đường ghi-thẳng không còn. Hàm vẫn ở lại và vẫn public: nó là lưới quét
     * theo BRAND, còn `ensureOpenRatePeriod()` chỉ vá được một hàng mà nó đang
     * cầm. Chừng nào còn hàng `tax_types` sinh ra ngoài `create()` — nhập dữ
     * liệu, khôi phục ảnh chụp — thì lưới này vẫn là thứ duy nhất phủ được.
     *
     * `ensureStandardTypesForBrand()` gọi nó ở cuối mỗi lượt, nên baseline
     * (Platform sync · tạo chi nhánh · seeder · `provisioning:reconcile`) đều
     * đóng luôn khoảng trống này.
     */
    public function ensureRatePeriodsForBrand(string $brandId): void
    {
        TaxType::query()
            ->where('brand_id', $brandId)
            ->get(['id', 'rate'])
            ->each(fn (TaxType $type) => $this->ensureOpenRatePeriod($type));
    }

    /**
     * Mọi tax type phải có MỘT kỳ hiệu lực đang mở trong `tax_type_rates`.
     *
     * Trước #2318 việc này do một data migration làm, nên brand tạo lúc CHẠY
     * (hook `Brand::created` → ensureStandardTypesForBrand) có tax type mà không
     * có kỳ hiệu lực nào — migration chỉ chạy một lần lúc deploy. Đặt ở đây thì
     * mọi đường tạo đều đi qua: seeder, hook, và admin tạo tay.
     *
     * `effective_from` lùi về 1900-01-01: kỳ đầu tiên phải phủ mọi đơn đã có,
     * kể cả đơn nhập từ ảnh chụp production có ngày sớm hơn lúc seed.
     */
    private function ensureOpenRatePeriod(TaxType $taxType): void
    {
        $exists = DB::table('tax_type_rates')
            ->where('tax_type_id', $taxType->id)
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();

        DB::table('tax_type_rates')->insert([
            'id' => (string) Str::uuid(),
            'tax_type_id' => $taxType->id,
            'rate' => $taxType->rate,
            'effective_from' => '1900-01-01',
            'effective_to' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Update a tax type. Same single-default-per-brand enforcement as create().
     * `code` is immutable and ignored if present.
     */
    public function update(TaxType $taxType, array $data): TaxType
    {
        unset($data['code']);

        return DB::transaction(function () use ($taxType, $data) {
            if (! empty($data['is_default'])) {
                $this->clearBrandDefault($taxType->brand_id, exceptId: $taxType->id);
            }

            $taxType->update($data);

            // plan-043 BUG-9 — same event-independent translation flush as
            // create(): an update that changes the name under WithoutModelEvents
            // (e.g. a re-run seeder) would otherwise silently drop the new name.
            $this->flushTranslations($taxType);

            return $taxType->loadCount('products')->load('translations');
        });
    }

    /**
     * plan-043 BUG-9 — persist Astrotomic translation sidecar rows without
     * depending on the model `saved` event (muted under Model::withoutEvents,
     * which DatabaseSeeder uses). Verbatim mirror of the generated
     * TaxTypeServiceBase::flushTranslations that this standalone service lost.
     */
    private function flushTranslations(TaxType $model): void
    {
        foreach ($model->translations as $translation) {
            if (! $translation->exists || $translation->isDirty()) {
                if (! empty($connectionName = $model->getConnectionName())) {
                    $translation->setConnection($connectionName);
                }
                $translation->setAttribute(
                    $model->getTranslationRelationKey(),
                    $model->getKey(),
                );
                $translation->save();
            }
        }
    }

    /**
     * Soft-delete a tax type. Guarded: throws TaxTypeInUseException (409) when
     * referenced by any product, menu-product override, or branch default.
     *
     * AUDIT FIX 3.7 (2026-07-14): the usage check + delete now run in ONE
     * transaction with the row locked, so a concurrent bulkDelete/toggle can't
     * interleave between check and write. (A concurrent product INSERT that
     * references the type between check and soft-delete remains theoretically
     * possible — soft deletes can't be fenced by the RESTRICT FK — but the
     * resolver ignores trashed types, so the residual window is read-safe.)
     */
    public function delete(TaxType $taxType): bool
    {
        return DB::transaction(function () use ($taxType) {
            $locked = TaxType::query()->lockForUpdate()->findOrFail($taxType->getKey());
            $usage = $this->usageCounts($locked);

            if (array_sum($usage) > 0) {
                throw new TaxTypeInUseException(
                    'Cannot delete a tax type that is still in use. Deactivate it instead.',
                    $usage,
                );
            }

            return $locked->delete();
        });
    }

    public function restore(TaxType $taxType): TaxType
    {
        $taxType->restore();

        return $taxType->loadCount('products')->load('translations');
    }

    /**
     * AUDIT FIX 3.8 (2026-07-14): deactivating a type that is still a LIVE
     * default (the brand default, or any branch's default_tax_type_id) used to
     * leave a stale config — the resolver kept loading the inactive type while
     * admin-web refused to re-save the setting (422). Deactivation is now
     * blocked (409 TAX_TYPE_IN_USE) until the defaults are re-pointed.
     * Product/menu-product references stay allowed — deactivation is exactly
     * "block NEW assignment, keep historical references valid".
     */
    public function toggleStatus(TaxType $taxType): TaxType
    {
        return DB::transaction(function () use ($taxType) {
            if ($taxType->is_active) {
                $defaults = [
                    'brand_default' => (int) $taxType->is_default,
                    'branch_defaults' => DB::table('shop_order_settings')
                        ->where('default_tax_type_id', $taxType->id)
                        ->count(),
                ];
                if (array_sum($defaults) > 0) {
                    throw new TaxTypeInUseException(
                        'Cannot deactivate a tax type that is still a brand or branch default. Reassign the default first.',
                        $defaults,
                    );
                }
            }

            $taxType->update(['is_active' => ! $taxType->is_active]);

            return $taxType->loadCount('products')->load('translations');
        });
    }

    /**
     * Lightweight list of active types for dropdowns.
     *
     * @return array<int, array{id: string, code: string, name: string, rate: string, is_default: bool}>
     */
    public function lookup(string $organizationId, ?string $brandId = null): array
    {
        $locale = app()->getLocale();
        $fallback = config('translatable.fallback_locale', 'en');

        return TaxType::query()
            ->select([
                'tax_types.id',
                'tax_types.code',
                'tax_types.rate',
                'tax_types.is_default',
                DB::raw('COALESCE(tr_current.`name`, tr_fallback.`name`) AS `name`'),
            ])
            ->leftJoin('tax_type_translations as tr_current', function ($j) use ($locale) {
                $j->on('tr_current.tax_type_id', '=', 'tax_types.id')
                    ->where('tr_current.locale', $locale);
            })
            ->leftJoin('tax_type_translations as tr_fallback', function ($j) use ($fallback) {
                $j->on('tr_fallback.tax_type_id', '=', 'tax_types.id')
                    ->where('tr_fallback.locale', $fallback);
            })
            ->where('tax_types.organization_id', $organizationId)
            ->when($brandId, fn ($q, $id) => $q->where('tax_types.brand_id', $id))
            ->where('tax_types.is_active', true)
            ->orderBy('tax_types.created_at', 'desc')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->getAttributes()['name'],
                'rate' => $row->rate,
                'is_default' => (bool) $row->is_default,
            ])
            ->toArray();
    }

    /**
     * Bulk soft-delete, applying the delete() guard per row. In-use rows report
     * their 409 usage counts; free rows are deleted.
     *
     * @param  array<int, string>  $ids
     * @return array{deleted: int, errors: array<int, array<string, mixed>>}
     */
    public function bulkDelete(array $ids): array
    {
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            $taxType = TaxType::find($id);

            if (! $taxType) {
                $errors[] = ['id' => $id, 'message' => 'Not found'];

                continue;
            }

            try {
                $this->delete($taxType);
                $deleted++;
            } catch (TaxTypeInUseException $e) {
                $errors[] = [
                    'id' => $id,
                    'code' => 'TAX_TYPE_IN_USE',
                    'message' => $e->getMessage(),
                    'meta' => $e->usage,
                ];
            } catch (ModelNotFoundException) {
                // Round-3 audit B3 (2026-07-14): delete() now re-fetches the
                // row under lock (findOrFail) — a row trashed by a concurrent
                // request between our find() above and the locked re-fetch
                // used to escape as a 404 and abort the WHOLE batch. Report it
                // per-row like any other miss instead.
                $errors[] = ['id' => $id, 'message' => 'Not found'];
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Count all live references to this tax type across the three RESTRICT FKs.
     *
     * @return array{products: int, menu_products: int, branch_defaults: int}
     */
    private function usageCounts(TaxType $taxType): array
    {
        return [
            'products' => DB::table('products')
                ->where('tax_type_id', $taxType->id)
                ->whereNull('deleted_at')
                ->count(),
            'menu_products' => DB::table('menu_products')
                ->where('tax_type_id', $taxType->id)
                ->whereNull('deleted_at')
                ->count(),
            'branch_defaults' => DB::table('shop_order_settings')
                ->where('default_tax_type_id', $taxType->id)
                ->count(),
        ];
    }

    /**
     * Clear the current default flag for every other tax type of a brand so at
     * most one row per brand carries is_default = true.
     */
    private function clearBrandDefault(string $brandId, ?string $exceptId = null): void
    {
        TaxType::query()
            ->where('brand_id', $brandId)
            ->where('is_default', true)
            ->when($exceptId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->update(['is_default' => false]);
    }
}
