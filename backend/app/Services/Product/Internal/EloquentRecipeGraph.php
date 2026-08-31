<?php

declare(strict_types=1);

namespace App\Services\Product\Internal;

use App\Models\Recipe;
use App\Services\Product\Contracts\RecipeGraph;

/**
 * #962 — bản cài Eloquent của {@see RecipeGraph}.
 *
 * Ba truy vấn được chép NGUYÊN HÌNH DẠNG từ `MaterialService`, kể cả việc lọc
 * `ingredients` TRONG PHP thay vì `JSON_CONTAINS`: plan-022 T4.3 ghi rõ lý do —
 * phép tra phải chạy được cả trên MySQL lẫn kết nối SQLite của test.
 */
final class EloquentRecipeGraph implements RecipeGraph
{
    public function activeIngredientCounts(array $materialIds): array
    {
        if ($materialIds === []) {
            return [];
        }

        return Recipe::query()
            ->whereIn('material_id', $materialIds)
            ->where('is_active', true)
            ->get(['material_id', 'ingredients'])
            ->mapWithKeys(fn (Recipe $recipe) => [
                (string) $recipe->material_id => is_array($recipe->ingredients) ? count($recipe->ingredients) : 0,
            ])
            ->all();
    }

    public function hasActiveRecipeWithIngredients(string $materialId): bool
    {
        return Recipe::query()
            ->where('material_id', $materialId)
            ->where('is_active', true)
            ->whereNotNull('ingredients')
            ->exists();
    }

    public function producedMaterialIdsConsuming(string $materialId): array
    {
        return Recipe::query()
            ->where('is_active', true)
            ->whereNotNull('material_id')
            ->where('material_id', '!=', $materialId)
            ->whereNotNull('ingredients')
            ->get(['material_id', 'ingredients'])
            ->filter(function (Recipe $recipe) use ($materialId) {
                foreach ((array) $recipe->ingredients as $ingredient) {
                    if (($ingredient['material_id'] ?? null) === $materialId) {
                        return true;
                    }
                }

                return false;
            })
            ->pluck('material_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }
}
