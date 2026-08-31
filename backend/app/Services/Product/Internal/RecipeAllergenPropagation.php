<?php

declare(strict_types=1);

namespace App\Services\Product\Internal;

use App\Models\Recipe;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Services\Product\AllergenRollupService;
use App\Services\Product\Contracts\MaterialAllergenPropagation;

/**
 * #962 — bản cài của {@see MaterialAllergenPropagation}.
 *
 * Ruột là bản chuyển nguyên vẹn của `MaterialService::propagateAllergenChange()`:
 * tính lại rollup hạ nguồn, rồi đẩy về `pending` những công thức ĐANG DUYỆT mà
 * rollup thật sự đổi (luật hai tầng — `recomputeForDownstreamRecipes` chỉ trả
 * về công thức có delta, nên "sửa không đổi gì" không đẩy).
 */
final class RecipeAllergenPropagation implements MaterialAllergenPropagation
{
    public function __construct(
        private readonly AllergenRollupService $rollup,
    ) {}

    public function propagateAllergenChange(string $materialId, string $organizationId): void
    {
        $changed = $this->rollup->recomputeForDownstreamRecipes($materialId, $organizationId);

        foreach ($changed as $recipe) {
            /** @var Recipe $recipe */
            if ($recipe->getApprovalStatus() === ApprovalStatusEnum::Approved) {
                $recipe->markAsPending();
                $recipe->logAudit('recipe.auto_repending', [
                    'source' => 'material_allergen_change',
                    'material_id' => $materialId,
                ]);
            }
        }
    }
}
