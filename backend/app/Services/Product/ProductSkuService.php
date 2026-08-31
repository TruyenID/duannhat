<?php

namespace App\Services\Product;

use App\Exceptions\SkuInMenuException;
use App\Models\MenuProductSku;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Services\Inventory\Contracts\ActiveMaterialDirectory;
use App\Services\Order\Contracts\OpenOrderSkuUsage;
use App\Traits\GeneratesSku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductSkuService
{
    use GeneratesSku;

    protected string $skuPrefix = 'PV';

    protected string $skuModel = ProductSku::class;

    // =========================================================================
    //  Query
    // =========================================================================

    /**
     * @param  array{search?: string, is_active?: bool, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function listForProduct(Product|string $product, array $filters = []): LengthAwarePaginator
    {
        $productId = $product instanceof Product ? $product->id : $product;

        $query = ProductSku::query()
            ->where('product_id', $productId)
            ->with(['recipe', 'units', 'optionValue1.option', 'optionValue2.option', 'optionValue3.option', 'galleryFirst']);

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
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

    /**
     * @param  array{organization_id?: string, brand_id?: string, search?: string, is_active?: bool, product_id?: string, with_trashed?: bool, sort?: string, per_page?: int}  $filters
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $query = ProductSku::query()
            ->with(['product:id,name', 'recipe', 'units', 'optionValue1', 'optionValue2', 'optionValue3']);

        if (! empty($filters['organization_id']) || ! empty($filters['brand_id'])) {
            $query->whereHas('product', function ($q) use ($filters) {
                if (! empty($filters['organization_id'])) {
                    $q->where('organization_id', $filters['organization_id']);
                }
                if (! empty($filters['brand_id'])) {
                    $q->where('brand_id', $filters['brand_id']);
                }
            });
        }

        $query->when($filters['product_id'] ?? null, fn ($q, $id) => $q->where('product_id', $id));

        $query->when($filters['search'] ?? null, function ($q, $search) {
            $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
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

    public function findById(string $id): ProductSku
    {
        return ProductSku::with([
            'product',
            'recipe.material',
            'units',
            'optionValue1.option',
            'optionValue2.option',
            'optionValue3.option',
            'gallery',
        ])->findOrFail($id);
    }

    // =========================================================================
    //  Create
    // =========================================================================

    public function create(array $data): ProductSku
    {
        return DB::transaction(function () use ($data) {
            $productId = $data['product_id'];

            if (empty($data['sku'])) {
                $data['sku'] = $this->generateUniqueSku(
                    additionalWhere: ['product_id' => $productId]
                );
            }

            $this->validateOptionValues($productId, $data);

            // option_signature is computed by ProductSkuObserver::saving();
            // we still need it locally to check for duplicates before insert.
            $signature = ProductSku::computeOptionSignature(
                $data['option_value1_id'] ?? null,
                $data['option_value2_id'] ?? null,
                $data['option_value3_id'] ?? null,
            );

            $duplicate = ProductSku::withTrashed()
                ->where('product_id', $productId)
                ->where('option_signature', $signature)
                ->exists();

            if ($duplicate) {
                throw ValidationException::withMessages([
                    'option_signature' => 'This option combination already exists for the product.',
                ]);
            }

            if (empty($data['name'])) {
                $data['name'] = $this->generateNameFromOptionValues($data);
            }

            if (! empty($data['recipe_id'])) {
                $data['cost_price_auto'] = $this->calculateCostFromRecipe($data['recipe_id']);
            }

            $units = $data['units'] ?? [];
            unset($data['units']);

            $id = $data['id'] ?? null;
            unset($data['id']);
            $sku = new ProductSku;
            $sku->fill($data);
            if ($id !== null) {
                $sku->forceFill(['id' => $id]);
            }
            $sku->save();

            foreach ($units as $unitData) {
                $sku->units()->create($unitData);
            }

            return $sku->load([
                'product', 'recipe.material', 'units',
                'optionValue1.option', 'optionValue2.option', 'optionValue3.option',
            ]);
        });
    }

    // =========================================================================
    //  Update
    // =========================================================================

    public function update(ProductSku $sku, array $data): ProductSku
    {
        return DB::transaction(function () use ($sku, $data) {
            $optionChanged = false;

            foreach (['option_value1_id', 'option_value2_id', 'option_value3_id'] as $fk) {
                if (array_key_exists($fk, $data)) {
                    $optionChanged = true;
                }
            }

            if ($optionChanged) {
                $merged = [
                    'option_value1_id' => $data['option_value1_id'] ?? $sku->option_value1_id,
                    'option_value2_id' => $data['option_value2_id'] ?? $sku->option_value2_id,
                    'option_value3_id' => $data['option_value3_id'] ?? $sku->option_value3_id,
                    'product_id' => $sku->product_id,
                ];

                $this->validateOptionValues($sku->product_id, $merged);
                // option_signature recomputed by observer on save
            }

            if (array_key_exists('recipe_id', $data) && $data['recipe_id'] !== $sku->recipe_id) {
                $data['cost_price_auto'] = $this->calculateCostFromRecipe($data['recipe_id']);
            }

            // Cost override semantics:
            //   - is_cost_override=false → cost_price always mirrors
            //     cost_price_auto, regardless of whether the request includes
            //     the flag explicitly or whether the flag transitioned.
            //   - is_cost_override=true  → leave cost_price as supplied (or
            //     keep the current user-controlled value).
            $effectiveOverride = array_key_exists('is_cost_override', $data)
                ? (bool) $data['is_cost_override']
                : (bool) $sku->is_cost_override;

            if ($effectiveOverride === false) {
                $auto = $data['cost_price_auto'] ?? $sku->cost_price_auto;
                $data['cost_price'] = $auto;
            }

            $sku->update($data);

            if ($sku->wasChanged('selling_price')) {
                $this->propagateSellingPriceToMenus($sku);
            }

            // Disabling the variant is NOT pushed to shops here — see
            // toggleStatus(): branch menus only mirror the "variant off" state
            // when the shop runs "Đồng bộ từ HQ" (syncFromMaster Step 1b).

            return $sku->load([
                'product', 'recipe.material', 'units',
                'optionValue1.option', 'optionValue2.option', 'optionValue3.option',
            ]);
        });
    }

    // =========================================================================
    //  Delete & Restore
    // =========================================================================

    public function delete(ProductSku $sku): bool
    {
        $blockingMenus = $this->blockingMenusFor($sku);

        if (! empty($blockingMenus)) {
            throw new SkuInMenuException(
                "Cannot delete SKU '{$sku->sku}': it is referenced by active menus.",
                $blockingMenus,
            );
        }

        $remainingCount = ProductSku::where('product_id', $sku->product_id)
            ->where('id', '!=', $sku->id)
            ->count();

        if ($remainingCount === 0) {
            throw ValidationException::withMessages([
                'sku' => 'Cannot delete the last SKU of a product.',
            ]);
        }

        // plan-042: block deleting a SKU still referenced by an open order (as a
        // line item or a topping). Soft delete bypasses the DB RESTRICT FK, so
        // the guard must live here.
        if (self::skuInOpenOrder([$sku->id])) {
            abort(response()->json([
                'message' => "Cannot delete SKU '{$sku->sku}': it is used by an open order.",
                'code' => 'PRODUCT_SKU_DELETE_BLOCKED_OPEN_ORDER',
            ], 409));
        }

        return $sku->delete();
    }

    /**
     * plan-042: are any of the given SKU ids referenced by an OPEN order — as a
     * line item or a topping? Shared by ProductSkuService + ProductService.
     *
     * @param  list<string>  $skuIds
     */
    public static function skuInOpenOrder(array $skuIds): bool
    {
        // #1622 — câu hỏi này thuộc về Ordering (nó biết trạng thái nào là "chưa
        // đóng" và món nằm ở đâu trong đơn). Trước đây Catalog tự đọc thẳng ba
        // bảng của Ordering; deptrac không thấy vì không import class nào.
        return app(OpenOrderSkuUsage::class)->anyOpenOrderUsesSkus(array_values($skuIds));
    }

    /**
     * Return the menus that block deleting this SKU.
     *
     * @return array<int, array{id: string, name: string}>
     */
    private function blockingMenusFor(ProductSku $sku): array
    {
        return MenuProductSku::where('product_sku_id', $sku->id)
            ->with('menuProduct.menu:id,name')
            ->get()
            ->map(fn (MenuProductSku $mps) => [
                'id' => $mps->menuProduct->menu->id,
                'name' => $mps->menuProduct->menu->name,
            ])
            ->unique('id')
            ->values()
            ->all();
    }

    public function restore(ProductSku $sku): ProductSku
    {
        $sku->restore();

        return $sku->load([
            'product', 'recipe.material', 'units',
            'optionValue1.option', 'optionValue2.option', 'optionValue3.option',
        ]);
    }

    // =========================================================================
    //  Actions
    // =========================================================================

    public function toggleStatus(ProductSku $sku): ProductSku
    {
        // Disabling a variant here does NOT push to the shops immediately — a
        // branch menu is only reconciled to HQ when the shop runs "Đồng bộ từ
        // HQ" (MenuService::syncFromMaster), which deactivates branch menu SKUs
        // pointing at an inactive ProductSku. This keeps every HQ template edit
        // consistent: the shop stays as-is until it explicitly syncs.
        $sku->update(['is_active' => ! $sku->is_active]);

        return $sku->load([
            'product', 'recipe.material', 'units',
            'optionValue1.option', 'optionValue2.option', 'optionValue3.option',
        ]);
    }

    /**
     * Nguyên liệu CHA đang dùng biến thể này (BOM).
     *
     * #962 — `materials` thuộc Inventory, nên câu truy vấn cuối đi qua
     * {@see ActiveMaterialDirectory}. Payload trả về KHÔNG đổi: controller
     * serialize thẳng collection này ra JSON và admin-web đọc nó.
     *
     * @return Collection<int, Model>
     */
    public function checkUsage(ProductSku $sku): Collection
    {
        // Plan-022 T4.3 — Recipe.ingredients is the canonical BOM source.
        // Walk active recipes and filter in PHP so the lookup stays portable
        // across MySQL (JSON_CONTAINS) and SQLite test connections.
        $parentMaterialIds = Recipe::query()
            ->where('is_active', true)
            ->whereNotNull('material_id')
            ->whereNotNull('ingredients')
            ->get(['material_id', 'ingredients'])
            ->filter(function (Recipe $recipe) use ($sku) {
                foreach ((array) $recipe->ingredients as $ingredient) {
                    if (($ingredient['variant_id'] ?? null) === $sku->id) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('material_id')
            ->unique()
            ->all();

        if ($parentMaterialIds === []) {
            return new Collection;
        }

        return app(ActiveMaterialDirectory::class)
            ->activeByIds(array_values($parentMaterialIds));
    }

    // =========================================================================
    //  Bulk: generate missing option-value combinations
    // =========================================================================

    /**
     * Cartesian-generate every (val1 × val2 × val3) combination from the
     * product's options and insert any SKU that doesn't already exist (matched
     * by option_signature). Returns the freshly created SKUs.
     *
     * Refuses if the product has no options, any option has no values, or the
     * total combination count exceeds {@see self::MAX_GENERATED_COMBINATIONS}.
     *
     * @return SupportCollection<int, ProductSku>
     */
    public function generateMissingCombinations(Product $product): SupportCollection
    {
        return DB::transaction(function () use ($product) {
            // Lock the product row so concurrent generate calls cannot race
            // and produce duplicate option_signature collisions.
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $options = ProductOption::where('product_id', $product->id)
                ->with(['values' => fn ($q) => $q->orderBy('position')])
                ->orderBy('position')
                ->get();

            if ($options->isEmpty()) {
                throw ValidationException::withMessages([
                    'options' => 'Cannot generate combinations: product has no options.',
                ]);
            }

            // Each option must have at least one value to participate in the
            // Cartesian product — otherwise the result would be empty.
            foreach ($options as $option) {
                if ($option->values->isEmpty()) {
                    throw ValidationException::withMessages([
                        'options' => "Cannot generate combinations: option '{$option->key}' has no values.",
                    ]);
                }
            }

            $cartesian = $this->cartesianProduct(
                $options->map(fn (ProductOption $o) => $o->values->all())->all()
            );

            if (count($cartesian) > self::MAX_GENERATED_COMBINATIONS) {
                throw ValidationException::withMessages([
                    'combinations' => 'Cannot generate combinations: '.count($cartesian)
                        .' exceeds the limit of '.self::MAX_GENERATED_COMBINATIONS.'.',
                ]);
            }

            $activeSignatures = ProductSku::where('product_id', $product->id)
                ->pluck('option_signature')
                ->flip()
                ->all();

            $deletedBySignature = ProductSku::onlyTrashed()
                ->where('product_id', $product->id)
                ->get()
                ->keyBy('option_signature');

            $created = collect();

            foreach ($cartesian as $combo) {
                /** @var array<int, ProductOptionValue> $combo */
                $valueIds = [null, null, null];
                foreach ($combo as $i => $value) {
                    $position = $options[$i]->position;
                    $valueIds[$position - 1] = $value->id;
                }

                $signature = ProductSku::computeOptionSignature(...$valueIds);

                if (isset($activeSignatures[$signature])) {
                    continue;
                }

                if (isset($deletedBySignature[$signature])) {
                    // Restore + reactivate the previous SKU instead of creating
                    // a fresh row. Old pricing fields are preserved on purpose
                    // (selling_price, cost_price, cost_price_auto) — re-adding a
                    // previously removed option value should bring the SKU back
                    // in its prior pricing state. Only ensure it is no longer
                    // trashed AND no longer inactive (it may have been
                    // deactivated before being soft-deleted).
                    $restoredSku = $deletedBySignature[$signature];
                    $restoredSku->restoreQuietly();

                    if (! $restoredSku->is_active) {
                        $restoredSku->is_active = true;
                        $restoredSku->saveQuietly();
                    }

                    $created->push($restoredSku->fresh());

                    continue;
                }

                $created->push($this->create([
                    'product_id' => $product->id,
                    'option_value1_id' => $valueIds[0],
                    'option_value2_id' => $valueIds[1],
                    'option_value3_id' => $valueIds[2],
                    'is_active' => true,
                ]));
            }

            return $created;
        });
    }

    /**
     * Maximum number of (option_value × option_value × option_value)
     * combinations that {@see self::generateMissingCombinations()} will
     * accept before refusing with a 422.
     */
    public const MAX_GENERATED_COMBINATIONS = 500;

    /**
     * Compute the Cartesian product of N input lists.
     *
     * @param  array<int, array<int, mixed>>  $lists
     * @return array<int, array<int, mixed>>
     */
    private function cartesianProduct(array $lists): array
    {
        $result = [[]];

        foreach ($lists as $list) {
            $next = [];
            foreach ($result as $combination) {
                foreach ($list as $item) {
                    $next[] = array_merge($combination, [$item]);
                }
            }
            $result = $next;
        }

        return $result;
    }

    // =========================================================================
    //  Lookup
    // =========================================================================

    /**
     * @param  array<int, string>  $includeIds
     * @return array<int, array{id: string, name: string, sku: string|null, product_id: string, product: array{id: string, name: string}|null, recipe: array{id: string, output_quantity: float|string|null, output_unit: string|null}|null, recipe_multiplier: float|string|null, inventory_mode: string|null}>
     */
    public function lookup(string $organizationId, array $includeIds = [], ?string $brandId = null): array
    {
        $query = ProductSku::query()
            ->with(['product:id,name', 'recipe:id,output_quantity,output_unit'])
            ->whereHas('product', function ($q) use ($organizationId, $brandId) {
                $q->where('organization_id', $organizationId);
                if ($brandId !== null) {
                    $q->where('brand_id', $brandId);
                }
            })
            ->where(function ($query) use ($includeIds) {
                $query->where('is_active', true);
                if ($includeIds !== []) {
                    $query->orWhereIn('id', $includeIds);
                }
            })
            ->select(['id', 'name', 'sku', 'product_id', 'recipe_id', 'recipe_multiplier', 'inventory_mode']);

        return $query->orderBy('name')->get()->map(fn ($sku) => [
            'id' => $sku->id,
            'name' => $sku->name,
            'sku' => $sku->sku,
            'product_id' => $sku->product_id,
            'product' => $sku->product ? [
                'id' => $sku->product->id,
                'name' => $sku->product->name,
            ] : null,
            // Recipe summary — ProductionOrder/new uses output_unit to seed
            // the order's output_unit field, and output_quantity helps the
            // operator pick a sensible planned_quantity multiple.
            'recipe' => $sku->recipe ? [
                'id' => $sku->recipe->id,
                'output_quantity' => $sku->recipe->output_quantity,
                'output_unit' => $sku->recipe->output_unit,
            ] : null,
            'recipe_multiplier' => $sku->recipe_multiplier,
            'inventory_mode' => $sku->inventory_mode instanceof \BackedEnum
                ? $sku->inventory_mode->value
                : $sku->inventory_mode,
        ])->all();
    }

    // =========================================================================
    //  Private Helpers
    // =========================================================================

    /**
     * Validate that each supplied option value FK belongs to the correct option
     * position within the same product.
     */
    private function validateOptionValues(string $productId, array $data): void
    {
        $options = ProductOption::where('product_id', $productId)
            ->pluck('id', 'position');

        for ($i = 1; $i <= 3; $i++) {
            $fk = "option_value{$i}_id";
            $valueId = $data[$fk] ?? null;

            if ($valueId === null) {
                continue;
            }

            $value = ProductOptionValue::with('option')->find($valueId);

            if (! $value) {
                throw ValidationException::withMessages([
                    $fk => "Option value '{$valueId}' for position {$i} does not exist.",
                ]);
            }

            if ($value->option->product_id !== $productId) {
                throw ValidationException::withMessages([
                    $fk => "Option value '{$valueId}' does not belong to this product.",
                ]);
            }

            if ($value->option->position !== $i) {
                throw ValidationException::withMessages([
                    $fk => "Option value '{$valueId}' belongs to position {$value->option->position}, "
                        ."but was assigned to position {$i}.",
                ]);
            }
        }
    }

    /**
     * Generate a SKU display name from loaded option values (e.g. "Red / Large").
     */
    private function generateNameFromOptionValues(array $data): string
    {
        $parts = [];

        for ($i = 1; $i <= 3; $i++) {
            $valueId = $data["option_value{$i}_id"] ?? null;

            if ($valueId === null) {
                continue;
            }

            $value = ProductOptionValue::find($valueId);

            if ($value) {
                $parts[] = $value->label ?? $value->value;
            }
        }

        if (empty($parts)) {
            $product = Product::find($data['product_id']);

            return $product ? $product->name.' - Default' : 'Default';
        }

        return implode(' / ', $parts);
    }

    private function calculateCostFromRecipe(?string $recipeId): float
    {
        if (! $recipeId) {
            return 0;
        }

        $recipe = Recipe::with('material')->find($recipeId);

        if (! $recipe || ! $recipe->material) {
            return 0;
        }

        return (float) $recipe->material->calculated_cost;
    }

    public function propagateSellingPriceToMenus(ProductSku $sku): void
    {
        app(MenuService::class)->propagateNonOverriddenMenuPrice($sku);
    }

    /**
     * Issue #875 — copy misfiled create-time price from cost_price to selling_price.
     *
     * @return array{moved: bool, selling_price: string|int|float|null}
     */
    public function backfillSellingFromCost(ProductSku $sku): array
    {
        if ((float) $sku->selling_price !== 0.0 || (float) $sku->cost_price <= 0) {
            return ['moved' => false, 'selling_price' => $sku->selling_price];
        }

        $sku->update([
            'selling_price' => $sku->cost_price,
            'cost_price' => 0,
            'is_cost_override' => false,
        ]);

        return ['moved' => true, 'selling_price' => $sku->selling_price];
    }

    public function recomputeOptionSignature(ProductSku $sku, string $newSignature): bool
    {
        if ($sku->option_signature === $newSignature) {
            return false;
        }

        DB::table('product_skus')
            ->where('id', $sku->id)
            ->update(['option_signature' => $newSignature]);

        return true;
    }

    /** @param  array<int, string>  $skuIds */
    public function syncRecipeSkuAssignments(string $recipeId, array $skuIds, string $brandId): void
    {
        if ($skuIds !== []) {
            $skus = ProductSku::with('product:id,brand_id')
                ->whereIn('id', $skuIds)
                ->get();

            foreach ($skus as $sku) {
                if ($sku->product && (string) $sku->product->brand_id !== $brandId) {
                    throw ValidationException::withMessages([
                        'sku_ids' => sprintf(
                            'SKU %s belongs to a different brand.',
                            $sku->sku ?? $sku->id,
                        ),
                    ]);
                }
            }
        }

        ProductSku::where('recipe_id', $recipeId)
            ->whereNotIn('id', $skuIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->update(['recipe_id' => null]);

        if ($skuIds !== []) {
            ProductSku::whereIn('id', $skuIds)
                ->where(function ($q) use ($recipeId) {
                    $q->whereNull('recipe_id')->orWhere('recipe_id', '!=', $recipeId);
                })
                ->update(['recipe_id' => $recipeId]);
        }
    }
}
