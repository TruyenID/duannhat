<?php

namespace App\Services\Product;

use App\Exceptions\ProductTypeInUseException;
use App\Models\ProductType;
use App\Traits\GeneratesSku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProductTypeService
{
    use GeneratesSku;

    protected string $skuPrefix = 'PT';

    protected string $skuModel = ProductType::class;

    /**
     * @param  array{organization_id: string, brand_id?: string|null, search?: string, is_active?: bool, product_form?: string, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = ProductType::query()
            ->withCount('products')
            ->with('translations');

        if (! empty($filters['organization_id'])) {
            $query->where('organization_id', $filters['organization_id']);
        }

        // Brand scope — set by HQ controllers from the {brandSlug} route param
        // via the ResolveBrandFromSlug middleware. Optional so non-HQ callers
        // (e.g. internal jobs) can still query org-wide.
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
        $query->when($filters['product_form'] ?? null, fn ($q, $form) => $q->where('product_form', $form));

        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');
        $query->orderBy($column, $direction);

        return $query->paginate($filters['per_page'] ?? 25);
    }

    public function findById(string $id): ProductType
    {
        return ProductType::withCount('products')->with('translations')->findOrFail($id);
    }

    public function create(array $data): ProductType
    {
        if (empty($data['code'])) {
            $data['code'] = $this->generateUniqueSku(
                column: 'code',
                additionalWhere: ['organization_id' => $data['organization_id'], 'brand_id' => $data['brand_id']]
            );
        }

        $productType = new ProductType;
        $productType->fill($data);
        if (isset($data['id'])) {
            $productType->forceFill(['id' => $data['id']]);
        }
        $productType->save();

        return $productType->loadCount('products')->load('translations');
    }

    public function update(ProductType $productType, array $data): ProductType
    {
        unset($data['code']);

        $productType->update($data);

        return $productType->loadCount('products')->load('translations');
    }

    public function delete(ProductType $productType): bool
    {
        if ($productType->products()->exists()) {
            throw new ProductTypeInUseException(
                'Cannot delete product type that has products. Remove all products first.'
            );
        }

        return $productType->delete();
    }

    public function restore(ProductType $productType): ProductType
    {
        $productType->restore();

        return $productType->loadCount('products')->load('translations');
    }

    public function toggleStatus(ProductType $productType): ProductType
    {
        $productType->update(['is_active' => ! $productType->is_active]);

        return $productType->loadCount('products')->load('translations');
    }

    /**
     * Idempotent brand-scoped product type by code (used by BrandCoreCatalogService).
     *
     * @param  array<string, string>  $translations  locale => name
     */
    public function ensureByCode(
        string $brandId,
        string $organizationId,
        string $code,
        array $defaults,
        array $translations,
    ): ProductType {
        $type = ProductType::query()
            ->where('brand_id', $brandId)
            ->where('code', $code)
            ->first();

        if ($type === null) {
            return $this->create([
                'organization_id' => $organizationId,
                'brand_id' => $brandId,
                'code' => $code,
                ...$defaults,
                ...collect($translations)->mapWithKeys(fn (string $name, string $locale): array => [
                    $locale => ['name' => $name],
                ])->all(),
            ]);
        }

        foreach ($translations as $locale => $name) {
            $trans = $type->translateOrNew($locale);
            $trans->name = $name;
            $trans->save();
        }

        return $type->load('translations');
    }

    /**
     * @return array<int, array{id: string, code: string, name: string, product_form: string, has_recipe: bool}>
     */
    public function lookup(string $organizationId, ?string $brandId = null): array
    {
        $locale = app()->getLocale();
        $fallback = config('translatable.fallback_locale', 'en');

        return ProductType::query()
            ->select([
                'product_types.id',
                'product_types.code',
                'product_types.product_form',
                'product_types.has_recipe',
                DB::raw('COALESCE(tr_current.`name`, tr_fallback.`name`) AS `name`'),
            ])
            ->leftJoin('product_type_translations as tr_current', function ($j) use ($locale) {
                $j->on('tr_current.product_type_id', '=', 'product_types.id')
                    ->where('tr_current.locale', $locale);
            })
            ->leftJoin('product_type_translations as tr_fallback', function ($j) use ($fallback) {
                $j->on('tr_fallback.product_type_id', '=', 'product_types.id')
                    ->where('tr_fallback.locale', $fallback);
            })
            ->where('product_types.organization_id', $organizationId)
            ->when($brandId, fn ($q, $id) => $q->where('product_types.brand_id', $id))
            ->where('product_types.is_active', true)
            ->orderBy('product_types.created_at', 'desc')
            ->get()
            ->map(fn ($row) => $row->getAttributes())
            ->toArray();
    }

    /** @param  array<string, string>  $localeNames */
    public function ensureComboProductType(string $organizationId, string $brandId, array $localeNames): ProductType
    {
        return $this->ensureByCode(
            brandId: $brandId,
            organizationId: $organizationId,
            code: 'combo',
            defaults: [
                'product_form' => 'physical',
                'has_recipe' => false,
                'is_inventory_tracked' => false,
                'is_active' => true,
            ],
            translations: $localeNames,
        );
    }
}
