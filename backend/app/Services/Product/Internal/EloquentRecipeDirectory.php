<?php

declare(strict_types=1);

namespace App\Services\Product\Internal;

use App\Models\Recipe;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Services\Product\Contracts\RecipeDirectory;
use App\Services\Product\Contracts\RecipeSnapshot;

/**
 * #1567 — hiện thực {@see RecipeDirectory}.
 *
 * Bốn truy vấn chép NGUYÊN từ `MaterialBatchService`, kể cả cột sắp xếp: hai
 * method dùng `updated_at`, một dùng `approved_at`. Đó không phải chi tiết
 * ngẫu nhiên — "công thức mới nhất" theo lần sửa cuối và theo lần duyệt cuối là
 * hai câu trả lời khác nhau, và đóng lô với sản-lượng-kỳ-vọng hỏi hai câu đó.
 *
 * Cố ý KHÔNG dùng `select()` hẹp như bản cũ: bản cũ chọn cột khác nhau ở mỗi
 * chỗ gọi (`['id','output_quantity']` chỗ này, `['id','is_active','approval_status']`
 * chỗ kia), nên một snapshot dựng từ đó sẽ có trường `null` **không phải vì dữ
 * liệu trống mà vì cột không được chọn** — đúng cái bẫy #1301 đã trả giá ở
 * `SellerRegistrationResolver`. Đọc đủ cột, dựng snapshot đầy đủ.
 */
final class EloquentRecipeDirectory implements RecipeDirectory
{
    public function find(string $recipeId): ?RecipeSnapshot
    {
        $recipe = Recipe::find($recipeId);

        return $recipe === null ? null : self::snapshot($recipe);
    }

    public function latestActiveApprovedForMaterial(string $materialId): ?RecipeSnapshot
    {
        $recipe = Recipe::query()
            ->where('material_id', $materialId)
            ->where('is_active', true)
            ->where('approval_status', ApprovalStatusEnum::Approved->value)
            ->orderByDesc('updated_at')
            ->first();

        return $recipe === null ? null : self::snapshot($recipe);
    }

    public function latestActiveForMaterial(string $materialId): ?RecipeSnapshot
    {
        $recipe = Recipe::query()
            ->where('material_id', $materialId)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->first();

        return $recipe === null ? null : self::snapshot($recipe);
    }

    public function latestApprovedForMaterial(string $materialId): ?RecipeSnapshot
    {
        $recipe = Recipe::query()
            ->where('material_id', $materialId)
            ->where('approval_status', ApprovalStatusEnum::Approved->value)
            ->orderByDesc('approved_at')
            ->first();

        return $recipe === null ? null : self::snapshot($recipe);
    }

    private static function snapshot(Recipe $recipe): RecipeSnapshot
    {
        $status = $recipe->approval_status instanceof ApprovalStatusEnum
            ? $recipe->approval_status
            : ApprovalStatusEnum::from((string) $recipe->approval_status);

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
