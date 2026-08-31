<?php

declare(strict_types=1);

namespace App\Services\Product\Internal;

use App\Models\ProductSku;
use App\Models\Recipe;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Omnify\Enums\ProductSkuInventoryModeEnum;
use App\Services\Product\Contracts\RecipeSnapshot;
use App\Services\Product\Contracts\SkuDirectory;
use App\Services\Product\Contracts\SkuSnapshot;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * #1567 — hiện thực {@see SkuDirectory}.
 *
 * Mỗi truy vấn chép nguyên từ chỗ nó vừa rời đi, kể cả `whereHas('product', …)`
 * — đó là phạm vi tổ chức của plan-040 C1 (TH.1), không phải bộ lọc trang trí.
 *
 * `with('product:id,name')` + `with('recipe')` giữ nguyên số truy vấn: bản cũ
 * cũng eager-load đúng hai quan hệ đó ở đường `calculate()`.
 */
final class EloquentSkuDirectory implements SkuDirectory
{
    public function findWithRecipe(string $skuId): ?SkuSnapshot
    {
        $sku = ProductSku::query()->with(['recipe', 'product:id,name'])->find($skuId);

        return $sku === null ? null : self::snapshot($sku);
    }

    public function findWithRecipeForOrganization(string $skuId, string $organizationId): ?SkuSnapshot
    {
        $sku = ProductSku::query()
            ->with(['recipe', 'product:id,name'])
            ->whereHas('product', fn ($q) => $q->where('organization_id', $organizationId))
            ->find($skuId);

        return $sku === null ? null : self::snapshot($sku);
    }

    public function getWithRecipeForOrganization(string $skuId, string $organizationId): SkuSnapshot
    {
        return $this->findWithRecipeForOrganization($skuId, $organizationId)
            ?? throw (new ModelNotFoundException)->setModel(ProductSku::class, [$skuId]);
    }

    public function activeWithRecipeForOrganization(string $organizationId, ?string $brandId = null): array
    {
        return ProductSku::query()
            ->whereNotNull('recipe_id')
            ->where('is_active', true)
            ->with(['recipe', 'product:id,name'])
            ->whereHas('product', function ($q) use ($organizationId, $brandId): void {
                $q->where('organization_id', $organizationId);
                if ($brandId !== null) {
                    $q->where('brand_id', $brandId);
                }
            })
            ->get()
            ->map(static fn (ProductSku $sku): SkuSnapshot => self::snapshot($sku))
            // SKU khai `recipe_id` nhưng hàng công thức đã bị xoá ⇒ bỏ. Bản cũ
            // lọc y hệt sau khi map; giữ nguyên để danh sách không mọc thêm
            // dòng `recipe: null` mà người gọi chưa từng phải xử lý.
            ->filter(static fn (SkuSnapshot $s): bool => $s->recipe !== null)
            ->values()
            ->all();
    }

    public function byIdsForOrganization(array $skuIds, string $organizationId): array
    {
        return $this->lookup($skuIds, $organizationId);
    }

    public function byIds(array $skuIds): array
    {
        return $this->lookup($skuIds, null);
    }

    /**
     * @param  list<string>  $skuIds
     * @return array<string, SkuSnapshot>
     */
    private function lookup(array $skuIds, ?string $organizationId): array
    {
        if ($skuIds === []) {
            return [];
        }

        $rows = ProductSku::query()
            ->whereIn('id', $skuIds)
            ->with(['recipe', 'product:id,name'])
            ->when(
                $organizationId !== null,
                fn ($q) => $q->whereHas('product', fn ($p) => $p->where('organization_id', $organizationId)),
            )
            ->get();

        $out = [];
        foreach ($rows as $sku) {
            $out[(string) $sku->id] = self::snapshot($sku);
        }

        return $out;
    }

    private static function snapshot(ProductSku $sku): SkuSnapshot
    {
        return new SkuSnapshot(
            id: (string) $sku->id,
            sku: $sku->sku === null ? null : (string) $sku->sku,
            name: $sku->name === null ? null : (string) $sku->name,
            productId: $sku->product_id === null ? null : (string) $sku->product_id,
            productName: $sku->product?->name === null ? null : (string) $sku->product->name,
            recipeMultiplier: (float) ($sku->recipe_multiplier ?? 1),
            recipe: $sku->recipe === null ? null : self::recipeSnapshot($sku->recipe),
            inventoryMode: self::inventoryMode($sku),
        );
    }

    /**
     * #1731 — `tryFrom() ?? MadeToOrder`, KHÔNG `from()`.
     *
     * Đây là đường bán hàng: một giá trị lạ trong cột (bản ghi cũ, import tay)
     * mà ném ngoại lệ ở đây sẽ **cuộn ngược cả giao dịch bán**. Chỗ gọi cũ đọc
     * `$sku->inventory_mode` rồi so `=== 'track_stock'`, nên giá trị lạ vốn đã
     * mang nghĩa "không trừ kho SKU" và đi tiếp im lặng — giữ nguyên đúng nghĩa
     * đó ở đây.
     */
    private static function inventoryMode(ProductSku $sku): ProductSkuInventoryModeEnum
    {
        $raw = $sku->inventory_mode;

        if ($raw instanceof ProductSkuInventoryModeEnum) {
            return $raw;
        }

        return ProductSkuInventoryModeEnum::tryFrom((string) $raw)
            ?? ProductSkuInventoryModeEnum::MadeToOrder;
    }

    private static function recipeSnapshot(Recipe $recipe): RecipeSnapshot
    {
        // #1731 — `tryFrom() ?? Pending`, KHÔNG `from()`. Từ #1731 đường TRỪ KHO
        // LÚC BÁN đi qua đây; `from()` ném trên giá trị lạ ⇒ cuộn ngược cả giao
        // dịch bán vì một cột trạng thái duyệt hỏng. Chỗ gọi cũ
        // (`StockDeductionService::recipeIsApproved`) dùng `tryFrom()` và coi
        // null là "chưa duyệt" ⇒ bỏ qua trừ nguyên liệu kèm cảnh báo. `Pending`
        // ở đây cho ra đúng kết cục đó.
        $status = $recipe->approval_status instanceof ApprovalStatusEnum
            ? $recipe->approval_status
            : (ApprovalStatusEnum::tryFrom((string) ($recipe->approval_status ?? '')) ?? ApprovalStatusEnum::Pending);

        return new RecipeSnapshot(
            id: (string) $recipe->id,
            materialId: $recipe->material_id === null ? null : (string) $recipe->material_id,
            isActive: (bool) $recipe->is_active,
            approvalStatus: $status,
            outputQuantity: (float) ($recipe->output_quantity ?? 0),
            outputUnit: $recipe->output_unit === null ? null : (string) $recipe->output_unit,
            ingredients: is_array($recipe->ingredients) ? array_values($recipe->ingredients) : [],
            yieldVarianceTolerancePct: (float) ($recipe->yield_variance_tolerance_pct ?? 0),
        );
    }
}
