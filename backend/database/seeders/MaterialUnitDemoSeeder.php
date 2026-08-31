<?php

namespace Database\Seeders;

use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\Organization;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds deterministic MaterialUnit fixtures for the TC-UNIT-100..111 manual
 * test suite under /hq/[brandSlug]/materials/[id]/units.
 *
 * Per brand, on top of materials produced by MaterialSeeder, this seeder
 * shapes a handful of materials so QA always finds the scenarios it needs:
 *
 *   • WHEAT-FLOUR    → 3 units: g (base) + kg + bag_25kg
 *                      Covers: TC-100 (list w/ base highlight), TC-102/103/104
 *                      (add unit + validation flows), TC-105 (switch base),
 *                      TC-106 (edit ratio), TC-107 (delete unused unit),
 *                      TC-109 (cannot delete base), TC-110 (unsaved guard),
 *                      TC-111 (API error — mock from FE side).
 *
 *   • MILK-WHOLE     → 2 units: ml (base) + bottle_1l
 *                      Spare material for TC-105/107/108 retries so QA
 *                      doesn't have to reseed mid-run.
 *
 *   • SOY-SAUCE      → 0 units (all existing rows wiped)
 *                      Covers: TC-101 (empty state + Add Unit CTA).
 *
 *   • SUGAR-WHITE    → 1 unit: g (base) only
 *                      Covers: TC-109 (delete base blocked) on a material
 *                      where promoting another unit first is required.
 *
 * Idempotent: deletes the targeted material's existing units first, then
 * inserts the canonical set. Safe to re-run any time.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=MaterialUnitDemoSeeder
 *
 * Note on TC-108 ("UNIT_IN_USE"): the backend currently does NOT block
 * deletion of a non-base unit referenced by stock_transaction_items.unit
 * (the column is a free string, not a FK). To exercise the UI guard, FE
 * should mock the 422 from the network layer. If the BE check lands later,
 * extend this seeder with a stock_transaction_items row pointing at one of
 * WHEAT-FLOUR's 'bag_25kg' to make the scenario real.
 */
class MaterialUnitDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command?->warn('MaterialUnitDemoSeeder skipped: no organizations.');

            return;
        }

        $plan = [
            'WHEAT-FLOUR' => [
                ['unit' => 'g', 'ratio' => 1.0, 'is_base' => true],
                ['unit' => 'kg', 'ratio' => 1000.0, 'is_base' => false],
                ['unit' => 'bag_25kg', 'ratio' => 25000.0, 'is_base' => false],
            ],
            'MILK-WHOLE' => [
                ['unit' => 'ml', 'ratio' => 1.0, 'is_base' => true],
                ['unit' => 'bottle_1l', 'ratio' => 1000.0, 'is_base' => false],
            ],
            'SOY-SAUCE' => [],
            'SUGAR-WHITE' => [
                ['unit' => 'g', 'ratio' => 1.0, 'is_base' => true],
            ],
        ];

        $materials = Material::query()
            ->where('organization_id', $org->id)
            ->get();

        $touched = 0;

        foreach ($materials as $material) {
            foreach ($plan as $skuSuffix => $units) {
                if (! str_ends_with((string) $material->sku, '-'.$skuSuffix)
                    && (string) $material->sku !== $skuSuffix) {
                    continue;
                }

                MaterialUnit::where('material_id', $material->id)->delete();

                foreach ($units as $u) {
                    MaterialUnit::create([
                        'material_id' => $material->id,
                        'unit' => $u['unit'],
                        'ratio' => $u['ratio'],
                        'is_base' => $u['is_base'],
                    ]);
                }

                $touched++;
                $this->command?->info(sprintf(
                    'MaterialUnitDemo: %s (%s) → %d units (%s)',
                    $material->sku,
                    $material->brand?->slug ?? '?',
                    count($units),
                    count($units) === 0 ? 'EMPTY' : implode(',', array_column($units, 'unit')),
                ));
            }
        }

        $this->command?->info("MaterialUnitDemoSeeder: shaped {$touched} materials across all brands.");
    }
}
