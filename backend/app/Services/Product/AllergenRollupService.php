<?php

namespace App\Services\Product;

use App\Models\Allergen;
use App\Models\Recipe;
use Illuminate\Support\Collection;

/**
 * Computes Recipe.allergen_rollup from upstream Material allergens and writes
 * it back synchronously inside the caller's DB transaction.
 *
 * Rollup sources for a Recipe:
 *   - The Recipe's own material_id → its allergens (M2M)
 *   - Any Recipe.ingredients[].material_id → that Material's allergens
 *   - TRANSITIVELY: when any of those Materials is itself a compound /
 *     produced material (i.e. some Recipe declares it as its output
 *     material_id), that sub-recipe's ingredient Materials — and their
 *     allergens — are folded in as well, walking the component graph to
 *     arbitrary depth. Without this a top-level Recipe that uses a
 *     semi-finished Material (e.g. "house sauce") would silently under-declare
 *     the allergens contributed by the sauce's raw ingredients (milk, soy…) —
 *     an HACCP / FALCPA under-declaration bug.
 *
 * Soft-deleted allergens are filtered out. Cycle safety is provided by the
 * `visited` set in the graph walk, so a mutual A→B→A component reference can
 * never spin forever.
 *
 * Rollup writes are NEVER queued — DESIGN Decision 3 requires synchronous
 * invalidation, since stale allergen data is a food-safety bug per FDA HACCP.
 */
class AllergenRollupService
{
    /**
     * Compute the allergen ID set for a Recipe. Returns a deduplicated,
     * sorted array of Allergen primary keys.
     *
     * @return array<int, string>
     */
    public function compute(Recipe $recipe): array
    {
        $materialIds = $this->collectMaterialIds($recipe);

        if ($materialIds->isEmpty()) {
            return [];
        }

        $allergenIds = Allergen::query()
            ->whereHas('materials', fn ($q) => $q->whereIn('materials.id', $materialIds))
            ->whereNull('allergens.deleted_at')
            ->pluck('allergens.id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $allergenIds;
    }

    /**
     * Recompute and persist the rollup for a single Recipe. Returns true if
     * the stored rollup actually changed (delta non-empty in either direction).
     */
    public function recomputeForRecipe(Recipe $recipe): bool
    {
        $newRollup = $this->compute($recipe);
        $oldRollup = $this->normalizeStoredRollup($recipe->allergen_rollup);

        if ($newRollup === $oldRollup) {
            return false;
        }

        $recipe->forceFill([
            'allergen_rollup' => $newRollup,
            'allergen_rollup_updated_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Recompute every downstream Recipe transitively affected by a change to
     * the given Material's allergen set. "Downstream" is not just recipes that
     * reference the Material directly (as material_id or ingredient) — it also
     * includes recipes that reach the Material through one or more layers of
     * compound / produced materials. Example: raw milk → sub-recipe "béchamel"
     * (output material) → recipe "lasagne" that lists béchamel as an
     * ingredient. Changing milk's allergens must recompute lasagne's rollup.
     *
     * Returns the recipes whose stored rollup actually changed — caller can
     * use this list to drive auto-repend on `approved` recipes.
     *
     * #962 — nhận ID chứ không nhận `App\Models\Material`: nguyên liệu thuộc
     * Inventory, và hai trường duy nhất method này đọc là `id` + `organization_id`.
     * Thu hẹp chữ ký gỡ luôn cạnh Catalog → Inventory mà không cần cổng nào.
     *
     * @return Collection<int, Recipe>
     */
    public function recomputeForDownstreamRecipes(string $materialId, string $organizationId): Collection
    {
        $graph = $this->loadRecipeGraph($organizationId);

        // Materials transitively BUILT FROM the changed material — the changed
        // material itself plus every compound material whose (sub-)recipe
        // reaches it. Walking up the component graph lets us find every recipe
        // whose rollup could shift, no matter how deep the nesting.
        $affectedMaterialIds = $this->affectedMaterialClosure($materialId, $graph);

        $changed = collect();

        Recipe::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->each(function (Recipe $recipe) use ($affectedMaterialIds, $changed) {
                if (! $this->recipeReferencesAny($recipe, $affectedMaterialIds)) {
                    return;
                }

                if ($this->recomputeForRecipe($recipe)) {
                    $changed->push($recipe);
                }
            });

        return $changed;
    }

    /**
     * The transitive closure of Material ids that feed a Recipe: its seed
     * materials (own output + direct ingredients) plus, for every compound
     * material among them, the ingredient materials of the recipe(s) that
     * produce it — recursively.
     *
     * @return Collection<int, string>
     */
    private function collectMaterialIds(Recipe $recipe): Collection
    {
        $seed = $this->seedMaterialIds($recipe);

        if ($seed->isEmpty()) {
            return $seed;
        }

        $graph = $this->loadRecipeGraph($recipe->organization_id);

        // producers[outputMaterialId] = [ingredient material ids...]
        $producers = [];
        foreach ($graph as $row) {
            $producers[$row['material_id']] = array_merge(
                $producers[$row['material_id']] ?? [],
                $row['ingredient_ids'],
            );
        }

        $visited = [];
        $queue = $seed->all();

        while ($queue !== []) {
            $mid = (string) array_shift($queue);

            if ($mid === '' || isset($visited[$mid])) {
                continue;
            }
            $visited[$mid] = true;

            foreach ($producers[$mid] ?? [] as $childId) {
                if (! isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return collect(array_keys($visited));
    }

    /**
     * The changed material plus every material transitively BUILT FROM it —
     * walking UP the component graph (child → parent output material).
     *
     * @param  Collection<int, array{material_id: string, ingredient_ids: array<int, string>}>  $graph
     * @return array<int, string>
     */
    private function affectedMaterialClosure(string $materialId, Collection $graph): array
    {
        // childToParents[ingredientMaterialId] = [output material ids that consume it]
        $childToParents = [];
        foreach ($graph as $row) {
            foreach ($row['ingredient_ids'] as $childId) {
                $childToParents[$childId][] = $row['material_id'];
            }
        }

        $affected = [];
        $queue = [$materialId];

        while ($queue !== []) {
            $mid = (string) array_shift($queue);

            if ($mid === '' || isset($affected[$mid])) {
                continue;
            }
            $affected[$mid] = true;

            foreach ($childToParents[$mid] ?? [] as $parentId) {
                if (! isset($affected[$parentId])) {
                    $queue[] = $parentId;
                }
            }
        }

        return array_keys($affected);
    }

    /**
     * The seed material ids directly named on a Recipe: its output material_id
     * plus each ingredient's material_id (deduped).
     *
     * @return Collection<int, string>
     */
    private function seedMaterialIds(Recipe $recipe): Collection
    {
        $ids = collect();

        if ($recipe->material_id) {
            $ids->push((string) $recipe->material_id);
        }

        foreach ($this->ingredientMaterialIds($recipe->ingredients) as $id) {
            $ids->push($id);
        }

        return $ids->unique()->values();
    }

    /**
     * Whether a Recipe references any of the given material ids, either as its
     * output material_id or in its ingredients JSON.
     *
     * @param  array<int, string>  $materialIds
     */
    private function recipeReferencesAny(Recipe $recipe, array $materialIds): bool
    {
        if ($materialIds === []) {
            return false;
        }

        $needles = array_flip($materialIds);

        if ($recipe->material_id && isset($needles[(string) $recipe->material_id])) {
            return true;
        }

        foreach ($this->ingredientMaterialIds($recipe->ingredients) as $id) {
            if (isset($needles[$id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load a lightweight, org-scoped view of every recipe that produces a
     * material — the edges of the component graph. Filtering in PHP keeps this
     * portable across MySQL and the SQLite test connection (no JSON_CONTAINS).
     *
     * @return Collection<int, array{material_id: string, ingredient_ids: array<int, string>}>
     */
    private function loadRecipeGraph($organizationId): Collection
    {
        return Recipe::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereNotNull('material_id')
            ->get(['material_id', 'ingredients'])
            ->map(fn (Recipe $recipe) => [
                'material_id' => (string) $recipe->material_id,
                'ingredient_ids' => $this->ingredientMaterialIds($recipe->ingredients),
            ]);
    }

    /**
     * Extract the non-empty ingredient material ids from an ingredients JSON
     * value (already cast to array by the model, but tolerant of raw JSON).
     *
     * @return array<int, string>
     */
    private function ingredientMaterialIds($ingredients): array
    {
        if (is_string($ingredients)) {
            $ingredients = json_decode($ingredients, true);
        }

        if (! is_array($ingredients)) {
            return [];
        }

        $ids = [];
        foreach ($ingredients as $ingredient) {
            if (is_array($ingredient) && ! empty($ingredient['material_id'])) {
                $ids[] = (string) $ingredient['material_id'];
            }
        }

        return $ids;
    }

    /**
     * @param  mixed  $stored
     * @return array<int, string>
     */
    private function normalizeStoredRollup($stored): array
    {
        if (! is_array($stored)) {
            return [];
        }

        return collect($stored)
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
