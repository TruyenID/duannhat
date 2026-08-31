<?php

namespace App\Services\Inventory;

use App\Models\Material;
use App\Models\StockLevel;
use App\Models\Warehouse;
use App\Services\Product\Contracts\RecipeSnapshot;
use App\Services\Product\Contracts\SkuDirectory;
use App\Services\Product\Contracts\SkuSnapshot;
use Illuminate\Support\Collection;

/**
 * Read-only capacity planner for production. Two user-facing operations:
 *
 * - `calculate`: given a warehouse + a list of SKUs with recipes, return
 *   the maximum producible quantity for each, the bottleneck ingredient,
 *   and cross-SKU shared ingredient warnings.
 * - `calculateShortage`: given a warehouse + one SKU + a desired output,
 *   list which ingredients (if any) are short and by how much.
 *
 * Ingredients are stored as a JSON blob on `Recipe.ingredients` — each
 * entry has `{ type: "variant"|"material"|"raw", id?, qty, unit?, name? }`.
 * "raw" is never stock-tracked and always reports `is_sufficient = null`.
 *
 * The service is stateless and builds its lookup maps inside each method
 * so the caller does not need to pre-hydrate anything beyond the inputs.
 */
class ProductionCalculatorService
{
    /** #1567 — cổng đọc SKU + công thức của Catalog. */
    private function skus(): SkuDirectory
    {
        return app(SkuDirectory::class);
    }

    /**
     * @return array<int, array{id: string, name: ?string, sku: ?string, product_id: ?string, recipe_multiplier: float|int, recipe: array{id: string, output_quantity: float, output_unit: ?string}}>
     */
    public function variantsWithRecipe(string $organizationId, ?string $brandId = null): array
    {
        return array_map(
            static fn (SkuSnapshot $sku): array => [
                'id' => $sku->id,
                'name' => $sku->name,
                'sku' => $sku->sku,
                'product_id' => $sku->productId,
                'recipe_multiplier' => $sku->recipeMultiplier,
                'recipe' => [
                    'id' => $sku->recipe->id,
                    'output_quantity' => $sku->recipe->outputQuantity,
                    'output_unit' => $sku->recipe->outputUnit,
                ],
            ],
            $this->skus()->activeWithRecipeForOrganization($organizationId, $brandId),
        );
    }

    /**
     * @param  array<int, string>  $variantIds
     * @return array{warehouse: array{id: string, name: string}, products: array<int, array<string, mixed>>, shared_ingredients: array<int, array<string, mixed>>}
     */
    public function calculate(
        string $organizationId,
        string $warehouseId,
        array $variantIds
    ): array {
        $warehouse = Warehouse::where('organization_id', $organizationId)
            ->findOrFail($warehouseId);

        if (empty($variantIds)) {
            return [
                'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name],
                'products' => [],
                'shared_ingredients' => [],
            ];
        }

        // Load variants + recipes; group by product for the output shape.
        $variants = $this->skus()->byIdsForOrganization($variantIds, $organizationId);

        [$variantLookup, $materialLookup, $stockByVariant, $stockByMaterial] =
            $this->buildStockContext($variants, $warehouseId, $organizationId);

        $productBuckets = [];
        // $ingredientUsageMap: track cross-SKU usage → shared ingredients.
        $ingredientUsageMap = [];

        foreach ($variants as $variant) {
            if (! $variant->recipe) {
                continue;
            }

            $productId = $variant->productId;
            $productName = $variant->productName ?? '—';
            $multiplier = $variant->recipeMultiplier;

            $ingredients = $this->analyseIngredients(
                $variant->recipe,
                $multiplier,
                $variantLookup,
                $materialLookup,
                $stockByVariant,
                $stockByMaterial,
                $productName,
                $ingredientUsageMap,
            );

            $nonRawMaxes = array_filter(
                array_map(fn ($i) => $i['max_batches_from_this'], $ingredients),
                fn ($v) => $v !== null
            );
            $maxProducible = empty($nonRawMaxes) ? 0 : (int) min($nonRawMaxes);
            if ($maxProducible === PHP_INT_MAX) {
                $maxProducible = 0;
            }

            $bottleneck = null;
            foreach ($ingredients as $ing) {
                if ($ing['max_batches_from_this'] !== null && $ing['max_batches_from_this'] === $maxProducible) {
                    $bottleneck = $ing['name'];
                    break;
                }
            }

            // #1614 — KHÔNG có `product.sku` ở đây, và đó là chủ ý.
            //
            // Bản đầu đọc `$variant->product?->code`, nhưng `products` chưa bao
            // giờ có cột `code` (không migration nào tạo nó; `Schema::hasColumn`
            // → false). Eloquent trả `null` cho thuộc tính không tồn tại thay vì
            // báo lỗi, nên trường này im lặng là `null` kể từ ngày viết ra —
            // trông như "dữ liệu còn thiếu" chứ không như "cột không tồn tại".
            //
            // Bỏ hẳn thay vì dựng cột: mỗi variant bên dưới ĐÃ mang `sku` thật
            // (`variants[].variant.sku`), nên đây là khái niệm trùng, không phải
            // dữ liệu thiếu. `products.slug` thì là khoá URL, không phải mã cho
            // người đọc — thay vào là lặng lẽ đổi nghĩa.
            $productBuckets[$productId] ??= [
                'product' => [
                    'id' => $productId,
                    'name' => $productName,
                ],
                'variants' => [],
            ];

            $productBuckets[$productId]['variants'][] = [
                'variant' => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'sku' => $variant->sku,
                    'recipe_multiplier' => $multiplier,
                ],
                'recipe' => [
                    'output_quantity' => $variant->recipe->outputQuantity,
                    'output_unit' => $variant->recipe->outputUnit,
                ],
                'max_producible' => $maxProducible,
                'bottleneck_ingredient' => $bottleneck,
                'ingredients' => $ingredients,
            ];
        }

        $shared = [];
        foreach ($ingredientUsageMap as $key => $entry) {
            if (count($entry['used_by_products']) > 1) {
                $shared[] = [
                    'ingredient_name' => $entry['ingredient_name'],
                    'track_key' => $key,
                    'used_by_products' => array_values(array_unique($entry['used_by_products'])),
                    'total_available' => $entry['total_available'],
                ];
            }
        }

        return [
            'warehouse' => ['id' => $warehouse->id, 'name' => $warehouse->name],
            'products' => array_values($productBuckets),
            'shared_ingredients' => $shared,
        ];
    }

    /**
     * @return array{desired_quantity: float, is_feasible: bool, ingredients: array<int, array<string, mixed>>}
     */
    public function calculateShortage(
        string $organizationId,
        string $warehouseId,
        string $variantId,
        float $desiredQuantity
    ): array {
        // #962 — cái ném nằm bên Catalog (`SkuDirectory::get…`): dựng ngoại lệ ở
        // đây bắt Inventory nêu tên `App\Models\ProductSku` cho một chuỗi trong
        // câu báo lỗi. Kiểu ngoại lệ không đổi, nên 404 vẫn y như cũ.
        $variant = $this->skus()->getWithRecipeForOrganization($variantId, $organizationId);

        if (! $variant->recipe) {
            return [
                'desired_quantity' => $desiredQuantity,
                'is_feasible' => true,
                'ingredients' => [],
            ];
        }

        $components = $this->decodeIngredients($variant->recipe->ingredients);
        $multiplier = $variant->recipeMultiplier;
        // plan-040 NEW-BP-3 (TH.2): production scales material need by
        // multiplier / output_quantity (ProductionOrderService /
        // MaterialBatchService). Mirror that here so shortage math matches.
        $outputQty = $variant->recipe->outputQuantity ?: 1.0;

        $variantIds = [];
        $materialIds = [];
        foreach ($components as $c) {
            // plan-040 C1 (TH.1): ingredients are keyed by `material_id` /
            // `variant_id` + `quantity` (RecipeService::normalizeIngredients),
            // not the legacy `id` / `qty`. Read the real keys (legacy fallback)
            // so the required totals are non-zero.
            $type = $c['type'] ?? 'material';
            $componentId = $this->resolveComponentId($c, $type);
            if ($componentId === null) {
                continue;
            }
            if ($type === 'variant') {
                $variantIds[] = $componentId;
            } elseif ($type === 'material') {
                $materialIds[] = $componentId;
            }
        }

        // plan-040 C1 (TH.1): org-scope the variant lookup so a recipe
        // referencing another tenant's SKU can't leak its name/stock.
        $variantLookup = $this->skus()->byIdsForOrganization($variantIds, $organizationId);
        $materialLookup = Material::whereIn('id', $materialIds)
            ->where('organization_id', $organizationId)
            ->get()
            ->keyBy('id');

        $stockByVariant = StockLevel::where('warehouse_id', $warehouseId)
            ->whereIn('product_sku_id', $variantIds)
            ->get()
            ->keyBy('product_sku_id');
        $stockByMaterial = StockLevel::where('warehouse_id', $warehouseId)
            ->whereIn('material_id', $materialIds)
            ->get()
            ->keyBy('material_id');

        $rows = [];
        $feasible = true;

        foreach ($components as $c) {
            $type = $c['type'] ?? 'material';
            $qty = $this->resolveComponentQty($c);
            $requiredPerBatch = $qty * $multiplier / max($outputQty, 1e-9);
            $requiredTotal = $requiredPerBatch * $desiredQuantity;
            $name = $c['name'] ?? '—';
            $unit = $c['unit'] ?? null;
            $componentId = $this->resolveComponentId($c, $type);

            $available = null;
            if ($type === 'variant' && $componentId !== null) {
                $name = $variantLookup[$componentId]->name ?? $name;
                $available = (float) ($stockByVariant[$componentId]->quantity ?? 0);
            } elseif ($type === 'material' && $componentId !== null) {
                $name = $materialLookup[$componentId]->name ?? $name;
                $available = (float) ($stockByMaterial[$componentId]->quantity ?? 0);
            }

            $shortage = $available === null
                ? 0.0
                : max(0.0, $requiredTotal - $available);
            $isSufficient = $available === null
                ? null
                : $shortage <= 0;

            if ($isSufficient === false) {
                $feasible = false;
            }

            $rows[] = [
                'name' => $name,
                'type' => $type,
                'required_total' => $requiredTotal,
                'available_stock' => $available,
                'shortage' => $shortage,
                'unit' => $unit,
                'is_sufficient' => $isSufficient,
            ];
        }

        return [
            'desired_quantity' => $desiredQuantity,
            'is_feasible' => $feasible,
            'ingredients' => $rows,
        ];
    }

    // =========================================================================
    //  Private helpers
    // =========================================================================

    /**
     * Pre-build variant + material + stock lookup maps once per calculate()
     * call so the per-variant loop stays O(ingredients) with only array
     * lookups.
     *
     * @param  list<SkuSnapshot>  $variants
     * @return array{0: array<string, SkuSnapshot>, 1: Collection<string, Material>, 2: Collection<string, StockLevel>, 3: Collection<string, StockLevel>}
     */
    private function buildStockContext(
        array $variants,
        string $warehouseId,
        string $organizationId
    ): array {
        $variantComponentIds = [];
        $materialComponentIds = [];

        foreach ($variants as $variant) {
            if (! $variant->recipe) {
                continue;
            }
            foreach ($this->decodeIngredients($variant->recipe->ingredients) as $c) {
                // plan-040 C1 (TH.1): real ingredient keys (material_id /
                // variant_id), legacy `id` fallback.
                $type = $c['type'] ?? 'material';
                $componentId = $this->resolveComponentId($c, $type);
                if ($componentId === null) {
                    continue;
                }
                if ($type === 'variant') {
                    $variantComponentIds[] = $componentId;
                } elseif ($type === 'material') {
                    $materialComponentIds[] = $componentId;
                }
            }
        }

        // plan-040 C1 (TH.1): org-scope the variant lookup (no cross-tenant leak).
        $variantLookup = $this->skus()->byIdsForOrganization($variantComponentIds, $organizationId);
        $materialLookup = Material::whereIn('id', $materialComponentIds)
            ->where('organization_id', $organizationId)
            ->get()
            ->keyBy('id');

        $stockByVariant = StockLevel::where('warehouse_id', $warehouseId)
            ->whereIn('product_sku_id', $variantComponentIds)
            ->get()
            ->keyBy('product_sku_id');
        $stockByMaterial = StockLevel::where('warehouse_id', $warehouseId)
            ->whereIn('material_id', $materialComponentIds)
            ->get()
            ->keyBy('material_id');

        return [$variantLookup, $materialLookup, $stockByVariant, $stockByMaterial];
    }

    /**
     * Normalise `Recipe.ingredients` JSON into an array of component rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function decodeIngredients(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }

    /**
     * @param  array<string, SkuSnapshot>  $variantLookup
     * @return array<int, array<string, mixed>>
     */
    private function analyseIngredients(
        RecipeSnapshot $recipe,
        float $multiplier,
        array $variantLookup,
        Collection $materialLookup,
        Collection $stockByVariant,
        Collection $stockByMaterial,
        string $productName,
        array &$ingredientUsageMap,
    ): array {
        // plan-040 NEW-BP-3 (TH.2): per-output-unit need = qty × multiplier /
        // output_quantity, matching the production explosion.
        $outputQty = $recipe->outputQuantity ?: 1.0;

        $rows = [];
        foreach ($this->decodeIngredients($recipe->ingredients) as $c) {
            // plan-040 C1 (TH.1): real ingredient keys (material_id / variant_id
            // + quantity), legacy `id` / `qty` fallback.
            $type = $c['type'] ?? 'material';
            $qty = $this->resolveComponentQty($c);
            $requiredPerBatch = $qty * $multiplier / max($outputQty, 1e-9);
            $name = $c['name'] ?? '—';
            $unit = $c['unit'] ?? null;
            $componentId = $this->resolveComponentId($c, $type);

            $available = null;
            $trackKey = null;

            if ($type === 'variant' && $componentId !== null) {
                $name = $variantLookup[$componentId]->name ?? $name;
                $available = (float) ($stockByVariant[$componentId]->quantity ?? 0);
                $trackKey = "variant:{$componentId}";
            } elseif ($type === 'material' && $componentId !== null) {
                $name = $materialLookup[$componentId]->name ?? $name;
                $available = (float) ($stockByMaterial[$componentId]->quantity ?? 0);
                $trackKey = "material:{$componentId}";
            }

            $maxBatches = null;
            if ($type !== 'raw') {
                if ($requiredPerBatch > 0 && $available !== null) {
                    $maxBatches = (int) floor($available / $requiredPerBatch);
                } else {
                    $maxBatches = PHP_INT_MAX;
                }
            }

            $rows[] = [
                'name' => $name,
                'type' => $type,
                'required_per_batch' => $requiredPerBatch,
                'available_stock' => $available,
                'max_batches_from_this' => $maxBatches,
                'unit' => $unit,
                'is_bottleneck' => false, // patched by caller once min is known
            ];

            if ($trackKey !== null) {
                if (! isset($ingredientUsageMap[$trackKey])) {
                    $ingredientUsageMap[$trackKey] = [
                        'ingredient_name' => $name,
                        'used_by_products' => [],
                        'total_available' => $available,
                    ];
                }
                $ingredientUsageMap[$trackKey]['used_by_products'][] = $productName;
            }
        }

        return $rows;
    }

    /**
     * plan-040 C1 (TH.1): resolve an ingredient component's target id from the
     * canonical recipe keys (`material_id` / `variant_id`), falling back to the
     * legacy flat `id` so pre-normalisation fixtures still resolve.
     *
     * @param  array<string, mixed>  $c
     */
    private function resolveComponentId(array $c, string $type): ?string
    {
        $id = match ($type) {
            'variant' => $c['variant_id'] ?? $c['id'] ?? null,
            'material' => $c['material_id'] ?? $c['id'] ?? null,
            default => null,
        };

        return ($id === null || $id === '') ? null : (string) $id;
    }

    /**
     * plan-040 C1 (TH.1): read an ingredient quantity from the canonical
     * `quantity` key, falling back to the legacy `qty`.
     *
     * @param  array<string, mixed>  $c
     */
    private function resolveComponentQty(array $c): float
    {
        return (float) ($c['quantity'] ?? $c['qty'] ?? 0);
    }
}
