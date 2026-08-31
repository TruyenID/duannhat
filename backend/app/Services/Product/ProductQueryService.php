<?php

namespace App\Services\Product;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Omnify\Enums\ProductStatusEnum;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

final readonly class ProductQueryService
{
    public function __construct(private CategoryService $categories, private ProductTypeService $productTypes, private ProductSkuService $skus) {}

    public function product(string $organizationId, string $brandId, string $id, bool $withTrashed = false): Product
    {
        return Product::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->with([
                'productType', 'categories', 'taxType', 'options.values', 'skus.units',
                'skus.recipe.material', 'skus.optionValue1.option', 'skus.optionValue2.option',
                'skus.optionValue3.option', 'skus.galleryFirst', 'gallery',
                'toppingGroups' => fn ($query) => $query->select('topping_groups.id'),
            ])
            ->withCount(['skus', 'options', 'skus as active_skus_count' => fn ($query) => $query->where('is_active', true)])
            ->findOrFail($id);
    }

    public function routableProduct(string $id, bool $withTrashed = false): Product
    {
        return Product::query()->when($withTrashed, fn ($query) => $query->withTrashed())->findOrFail($id);
    }

    /** @param array<string, mixed> $filters */
    public function products(string $organizationId, string $brandId, array $filters = []): LengthAwarePaginator
    {
        $query = Product::query()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->with(['productType', 'categories', 'galleryFirst', 'taxType'])
            ->withCount(['skus', 'options', 'skus as active_skus_count' => fn ($query) => $query->where('is_active', true)]);

        $query->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query
            ->whereTranslationLike('name', "%{$search}%")
            ->orWhere('slug', 'like', "%{$search}%")));
        $query->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($filters['product_type_id'] ?? null, fn ($query, $id) => $query->where('product_type_id', $id));
        $query->when($filters['category_id'] ?? null, fn ($query, $id) => $query->whereHas('categories', fn ($query) => $query->where('categories.id', $id)));
        $query->when(array_key_exists('is_hidden', $filters) && $filters['is_hidden'] !== null, fn ($query) => $query->where('is_hidden', $filters['is_hidden']));

        if ($filters['only_trashed'] ?? false) {
            $query->onlyTrashed();
        } elseif ($filters['with_trashed'] ?? false) {
            $query->withTrashed();
        }

        $sort = $filters['sort'] ?? '-created_at';
        $column = ltrim($sort, '-');
        if (! in_array($column, ['created_at', 'updated_at', 'name', 'status', 'slug'], true)) {
            $column = 'created_at';
        }

        return $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate($filters['per_page'] ?? 25);
    }

    /** @return array<int, array{id: string, name: string, slug: string|null}> */
    public function productLookup(string $organizationId, string $brandId): array
    {
        return Product::query()
            ->where('organization_id', $organizationId)
            ->where('brand_id', $brandId)
            ->where('status', ProductStatusEnum::Active->value)
            ->where('is_hidden', false)
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->toArray();
    }

    /** @param array<string, mixed> $filters */
    public function skusForProduct(string $organizationId, string $brandId, string $productId, array $filters): LengthAwarePaginator
    {
        $product = $this->product($organizationId, $brandId, $productId);

        return $this->skus->listForProduct($product, $filters);
    }

    /** @param array<string, mixed> $filters */
    public function skus(string $organizationId, string $brandId, array $filters): LengthAwarePaginator
    {
        return $this->skus->list(['organization_id' => $organizationId, 'brand_id' => $brandId, ...$filters]);
    }

    public function sku(string $organizationId, string $brandId, string $id, bool $withTrashed = false): ProductSku
    {
        return ProductSku::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->whereHas('product', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('brand_id', $brandId))
            ->with([
                'product', 'recipe.material', 'units',
                'optionValue1.option', 'optionValue2.option', 'optionValue3.option',
                'gallery',
            ])
            ->findOrFail($id);
    }

    /** @param list<string> $ids */
    public function skusByIds(string $organizationId, string $brandId, array $ids): Collection
    {
        return ProductSku::whereIn('id', $ids)
            ->whereHas('product', fn ($query) => $query
                ->where('organization_id', $organizationId)
                ->where('brand_id', $brandId))
            ->get();
    }

    /** @param list<string> $includeIds */
    public function skuLookup(string $organizationId, string $brandId, array $includeIds = []): array
    {
        return $this->skus->lookup($organizationId, $includeIds, $brandId);
    }

    public function skuUsage(ProductSku $sku): Collection
    {
        return $this->skus->checkUsage($sku);
    }

    public function options(string $organizationId, string $brandId, string $productId): Collection
    {
        $product = $this->product($organizationId, $brandId, $productId);

        return ProductOption::where('product_id', $product->id)->with('values')->orderBy('position')->get();
    }

    public function option(string $organizationId, string $brandId, string $id): ProductOption
    {
        return ProductOption::whereHas('product', fn ($query) => $query->where('organization_id', $organizationId)->where('brand_id', $brandId))->with(['product', 'values'])->findOrFail($id);
    }

    public function routableOption(string $id): ProductOption
    {
        return ProductOption::with(['product', 'values'])->findOrFail($id);
    }

    public function trashedOptionByKey(string $organizationId, string $brandId, string $productId, string $key): ?ProductOption
    {
        return ProductOption::onlyTrashed()
            ->where('product_id', $productId)
            ->where('key', $key)
            ->whereHas('product', fn ($query) => $query->where('organization_id', $organizationId)->where('brand_id', $brandId))
            ->first();
    }

    public function optionValues(string $organizationId, string $brandId, string $optionId): Collection
    {
        $option = $this->option($organizationId, $brandId, $optionId);

        return ProductOptionValue::where('option_id', $option->id)->orderBy('position')->get();
    }

    public function optionValue(string $organizationId, string $brandId, string $id): ProductOptionValue
    {
        return ProductOptionValue::whereHas('option.product', fn ($query) => $query->where('organization_id', $organizationId)->where('brand_id', $brandId))->with('option.product')->findOrFail($id);
    }

    public function routableOptionValue(string $id): ProductOptionValue
    {
        return ProductOptionValue::with('option.product')->findOrFail($id);
    }

    public function trashedOptionValueBySlug(string $organizationId, string $brandId, string $optionId, string $value): ?ProductOptionValue
    {
        return ProductOptionValue::onlyTrashed()
            ->where('option_id', $optionId)
            ->where('value', $value)
            ->whereHas('option.product', fn ($query) => $query->where('organization_id', $organizationId)->where('brand_id', $brandId))
            ->first();
    }

    /** @param array<string, mixed> $filters */
    public function categories(array $filters): LengthAwarePaginator
    {
        return $this->categories->list($filters);
    }

    public function category(string $organizationId, string $brandId, string $id): Category
    {
        return Category::where('organization_id', $organizationId)->where('brand_id', $brandId)->findOrFail($id)->loadMissing(['translations', 'parent', 'children'])->loadCount(['children', 'products']);
    }

    /** @return array<int, array{id: string, name: string, sku: string|null, parent_id: string|null}> */
    public function categoryLookup(string $organizationId, string $brandId): array
    {
        return $this->categories->lookup($organizationId, $brandId);
    }

    /** @param array<string, mixed> $filters */
    public function productTypes(array $filters): LengthAwarePaginator
    {
        return $this->productTypes->list($filters);
    }

    public function productType(string $organizationId, string $brandId, string $id, bool $withTrashed = false): ProductType
    {
        return ProductType::query()->when($withTrashed, fn ($query) => $query->withTrashed())->where('organization_id', $organizationId)->where('brand_id', $brandId)->findOrFail($id)->loadMissing('translations')->loadCount('products');
    }

    /** @return array<int, array{id: string, code: string, name: string, product_form: string, has_recipe: bool}> */
    public function productTypeLookup(string $organizationId, string $brandId): array
    {
        return $this->productTypes->lookup($organizationId, $brandId);
    }
}
