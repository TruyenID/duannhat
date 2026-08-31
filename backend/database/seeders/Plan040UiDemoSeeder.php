<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\ProductSku;
use App\Models\Recipe;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * plan-040 UI-demo top-up — makes two plan-040 features reachable in the admin UI
 * for manual testing. Purely additive, fully idempotent, and defensive (any
 * failure is logged, never aborts the seed). Safe to chain at the very end.
 *
 *   1. Second warehouse per single-warehouse shop — stock transfers (cluster D:
 *      receive validation, shrinkage, base-qty, cancel reversal) need a source
 *      AND a destination warehouse; many seeded shops only have one.
 *   2. One recipe linked to one variant per brand that has recipes but no
 *      variant-with-recipe — the Production Calculator (cluster H: C1 / NEW-BP-3)
 *      lists variants whose product_sku.recipe_id is set; without a link the
 *      calculator has nothing to select.
 */
class Plan040UiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSecondWarehouses();
        $this->linkRecipesToVariants();
    }

    private function seedSecondWarehouses(): void
    {
        $created = 0;

        Branch::query()->where('is_active', true)->get()->each(function (Branch $branch) use (&$created) {
            try {
                $warehouses = Warehouse::where('branch_id', $branch->id)->get();
                if ($warehouses->count() !== 1) {
                    return; // 0 (nothing to clone) or already ≥2 (idempotent skip)
                }

                $primary = $warehouses->first();
                $newCode = $primary->code.'-2';
                if (Warehouse::where('organization_id', $primary->organization_id)->where('code', $newCode)->exists()) {
                    return;
                }

                $secondary = $primary->replicate();
                $secondary->code = $newCode;
                $secondary->name = $primary->name.' (Sub)';
                $secondary->save();
                $created++;
            } catch (Throwable $e) {
                $this->command?->warn("Plan040UiDemoSeeder: skip 2nd warehouse for branch {$branch->id}: {$e->getMessage()}");
            }
        });

        $this->command?->info("Plan040UiDemoSeeder: {$created} secondary warehouse(s) created (transfer testing).");
    }

    private function linkRecipesToVariants(): void
    {
        $linked = 0;

        Recipe::query()
            ->select('brand_id')
            ->whereNotNull('brand_id')
            ->distinct()
            ->pluck('brand_id')
            ->each(function (string $brandId) use (&$linked) {
                try {
                    $alreadyLinked = ProductSku::whereNotNull('recipe_id')
                        ->whereHas('product', fn ($q) => $q->where('brand_id', $brandId))
                        ->exists();
                    if ($alreadyLinked) {
                        return; // idempotent — this brand can already be tested
                    }

                    $recipe = Recipe::where('brand_id', $brandId)->first();
                    $sku = ProductSku::whereNull('recipe_id')
                        ->whereHas('product', fn ($q) => $q->where('brand_id', $brandId))
                        ->first();

                    if ($recipe && $sku) {
                        $sku->recipe_id = $recipe->id;
                        $sku->save();
                        $linked++;
                    }
                } catch (Throwable $e) {
                    $this->command?->warn("Plan040UiDemoSeeder: skip recipe link for brand {$brandId}: {$e->getMessage()}");
                }
            });

        $this->command?->info("Plan040UiDemoSeeder: {$linked} variant(s) linked to a recipe (calculator testing).");
    }
}
