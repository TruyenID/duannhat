<?php

namespace Database\Seeders;

use App\Models\Allergen;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\MaterialLotService;
use App\Services\Product\MaterialService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds common raw materials with JP allergen associations.
 *
 * Requires: AllergenSeeder (JP jurisdiction allergens must exist).
 *
 * Idempotent: matches on (organization_id, brand_id, sku). Re-running
 * updates translations and allergen associations without duplicating rows.
 *
 * Usage:
 *   php artisan db:seed --class=MaterialSeeder
 */
class MaterialSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(MaterialService $service, MaterialLotService $lotService): void
    {
        $org = Organization::first();

        if (! $org) {
            $this->command?->warn('MaterialSeeder skipped: no organizations found. Run the main seeder first.');

            return;
        }

        $brands = Brand::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->get();

        if ($brands->isEmpty()) {
            $this->command?->warn('MaterialSeeder skipped: no active brands found.');

            return;
        }

        foreach ($brands as $brand) {
            $orgId = $org->id;
            $brandId = $brand->id;

            // Resolve JP allergens for this org (seeded by AllergenSeeder).
            $jpAllergens = Allergen::where('organization_id', $orgId)
                ->where('jurisdiction', 'jp')
                ->get()
                ->keyBy('code');

            // Prefix the demo SKU with the brand slug so the UNIQUE(org,sku)
            // constraint doesn't force every brand to share the same pool
            // (which would leave sibling brands with zero materials). Use
            // the full slug — truncating risked collisions across brands
            // with the same leading characters (e.g. "betoya" vs "beto-coffee").
            $skuPrefix = strtoupper(preg_replace('/[^a-z0-9]/i', '', $brand->slug) ?: 'B');

            foreach ($this->materialDefs() as $def) {
                $scopedSku = $skuPrefix.'-'.$def['sku'];
                $existing = Material::where('organization_id', $orgId)
                    ->where('sku', $scopedSku)
                    ->first();

                if ($existing && $existing->brand_id !== $brandId) {
                    continue;
                }

                $payload = [
                    'organization_id' => $orgId,
                    'brand_id' => $brandId,
                    'sku' => $scopedSku,
                    'name:ja' => $def['name']['ja'],
                    'name:en' => $def['name']['en'],
                    'name:vi' => $def['name']['vi'],
                    'description:ja' => $def['description']['ja'] ?? null,
                    'description:en' => $def['description']['en'] ?? null,
                    'description:vi' => $def['description']['vi'] ?? null,
                    'yield_quantity' => $def['yield_quantity'],
                    // Plan-022 T2 — raw materials (no Recipe/BOM) must have
                    // null yield_unit. The per-def `yield_unit` is still used
                    // below as the MaterialLot unit (see seedDemoLots).
                    'calculated_cost' => $def['calculated_cost'],
                    'is_active' => true,
                ];

                if ($existing) {
                    $allergenIds = collect($def['allergen_codes'])
                        ->map(fn ($code) => $jpAllergens[$code]?->id)
                        ->filter()
                        ->values()
                        ->toArray();

                    $service->update($existing, array_merge($payload, [
                        'allergen_ids' => $allergenIds,
                    ]));
                } else {
                    $material = $service->create($payload);

                    $allergenIds = collect($def['allergen_codes'])
                        ->map(fn ($code) => $jpAllergens[$code]?->id)
                        ->filter()
                        ->values()
                        ->toArray();

                    if (! empty($allergenIds)) {
                        $material->allergens()->sync($allergenIds);
                    }
                }
            }

            $this->command?->info('MaterialSeeder: seeded '.count($this->materialDefs())." materials for brand {$brand->slug}.");

            $this->seedDemoLots($lotService, $orgId, $brand, $brand->slug);
        }
    }

    /**
     * Seed 2-3 demo MaterialLots per material via MaterialLotService::receive
     * (per convention #3 — never raw Eloquent for translatable / cross-table
     * domain logic). Spreads expiries so the FEFO + expiring-soon UIs have
     * realistic data: one fresh, one expiring within a week, occasional
     * already-expired lot for the disposal flow.
     */
    private function seedDemoLots(
        MaterialLotService $lotService,
        string $orgId,
        Brand $brand,
        string $brandSlug,
    ): void {
        $brandId = $brand->id;

        // Pick a warehouse whose branch belongs to THIS brand. Branches link
        // to brands via the legacy `console_brand_id` (NOT brand.id, UUID v7
        // — see MockDataSeeder::seedBranches). MaterialLotService::receive
        // uses the same indirection in its cross-brand guard.
        $warehouse = Warehouse::query()
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->whereHas('branch', fn ($q) => $q->where('console_brand_id', $brand->console_brand_id))
            ->first();

        if (! $warehouse) {
            $this->command?->info("MaterialLot demo skipped for {$brandSlug}: no warehouse with matching branch brand.");

            return;
        }

        // StockTransaction.created_by_id is NOT NULL — receive() writes a
        // stock-in transaction so we need an actor. Users tie to org via
        // `console_organization_id`, not `organization_id`.
        $consoleOrgId = Organization::find($orgId)?->console_organization_id;
        $actor = User::query()
            ->when($consoleOrgId, fn ($q) => $q->where('console_organization_id', $consoleOrgId))
            ->first();

        $materials = Material::query()
            ->where('organization_id', $orgId)
            ->where('brand_id', $brandId)
            ->get();

        $suppliers = ['日本製粉', 'メイトーフード', 'カゴメ', '日清製粉', '味の素'];

        // Plan-022 T2 — Material.yield_unit is null for raw materials, so map
        // each def's base sku → its display unit and look it up by sku suffix.
        $unitBySkuSuffix = collect($this->materialDefs())
            ->mapWithKeys(fn ($def) => [$def['sku'] => $def['yield_unit']])
            ->all();

        $created = 0;
        foreach ($materials as $idx => $material) {
            // Skip if this material already has lots (idempotent).
            $existingLots = MaterialLot::where('material_id', $material->id)->count();
            if ($existingLots > 0) {
                continue;
            }

            $lotPlan = [
                ['days_out' => 90, 'qty' => 5000, 'supplier' => $suppliers[$idx % count($suppliers)]],
                ['days_out' => 5, 'qty' => 1000, 'supplier' => $suppliers[($idx + 1) % count($suppliers)]],
            ];

            // Every 3rd material also gets an expired lot for disposal demos.
            if ($idx % 3 === 0) {
                $lotPlan[] = ['days_out' => -2, 'qty' => 500, 'supplier' => $suppliers[($idx + 2) % count($suppliers)]];
            }

            foreach ($lotPlan as $plan) {
                try {
                    $payload = [
                        'organization_id' => $orgId,
                        'material_id' => $material->id,
                        'warehouse_id' => $warehouse->id,
                        'supplier_name' => $plan['supplier'],
                        'supplier_lot_code' => sprintf('SUP-%s-%d', strtoupper(substr($material->sku ?? 'GEN', -4)), $plan['days_out']),
                        'received_at' => now()->subDays(max(1, abs($plan['days_out']) + 1)),
                        'expiry_date' => now()->addDays($plan['days_out'])->format('Y-m-d'),
                        'received_qty' => (float) $plan['qty'],
                        'unit' => $this->resolveLotUnit($material, $unitBySkuSuffix),
                        'cost_per_unit' => 100.0,
                        'created_by_id' => $actor?->id,
                    ];

                    // Tier 1.D — supply an in-range temperature when the
                    // material requires a CCP check.
                    if ($material->requires_temperature_check) {
                        $min = $material->temperature_min !== null ? (float) $material->temperature_min : null;
                        $max = $material->temperature_max !== null ? (float) $material->temperature_max : null;
                        if ($min !== null && $max !== null) {
                            $payload['received_temperature'] = ($min + $max) / 2;
                        } elseif ($min !== null) {
                            $payload['received_temperature'] = $min;
                        } elseif ($max !== null) {
                            $payload['received_temperature'] = $max;
                        } else {
                            $payload['received_temperature'] = 4.0;
                        }
                    }

                    $lotService->receive($payload);
                    $created++;
                } catch (\Throwable $e) {
                    $this->command?->warn("MaterialLot demo skip {$material->sku}: ".$e->getMessage());
                }
            }
        }

        $this->command?->info("MaterialLot demo: seeded {$created} lots for brand {$brandSlug}.");
    }

    /**
     * Match the lot unit to the def by stripping the brand-slug prefix from
     * the material sku (see $skuPrefix in run()). Falls back to 'kg'.
     *
     * @param  array<string, string>  $unitBySkuSuffix
     */
    private function resolveLotUnit(Material $material, array $unitBySkuSuffix): string
    {
        $sku = (string) ($material->sku ?? '');
        foreach ($unitBySkuSuffix as $suffix => $unit) {
            if (str_ends_with($sku, '-'.$suffix) || $sku === $suffix) {
                return $unit;
            }
        }

        return 'kg';
    }

    /**
     * @return array<int, array{sku: string, name: array<string, string>, description: array<string, string>, yield_quantity: float, yield_unit: string, calculated_cost: float, allergen_codes: array<int, string>}>
     */
    private function materialDefs(): array
    {
        return [
            [
                'sku' => 'MA-WHEAT-FLOUR',
                'name' => [
                    'ja' => '小麦粉',
                    'en' => 'Wheat Flour',
                    'vi' => 'Bột mì',
                ],
                'description' => [
                    'ja' => '多目的小麦粉。生地やソースのベースに使用。',
                    'en' => 'All-purpose wheat flour. Used as base for doughs and sauces.',
                    'vi' => 'Bột mì đa dụng. Dùng làm nền cho bột và sốt.',
                ],
                'yield_quantity' => 1000.0,
                'yield_unit' => 'g',
                'calculated_cost' => 80.0,
                'allergen_codes' => ['wheat'],
            ],
            [
                'sku' => 'MA-EGG-WHOLE',
                'name' => [
                    'ja' => '全卵',
                    'en' => 'Whole Egg',
                    'vi' => 'Trứng nguyên quả',
                ],
                'description' => [
                    'ja' => '新鮮な鶏卵。製菓・料理に広く使用。',
                    'en' => 'Fresh hen egg. Widely used in baking and cooking.',
                    'vi' => 'Trứng gà tươi. Dùng rộng rãi trong làm bánh và nấu ăn.',
                ],
                'yield_quantity' => 50.0,
                'yield_unit' => 'g',
                'calculated_cost' => 25.0,
                'allergen_codes' => ['egg'],
            ],
            [
                'sku' => 'MA-MILK-WHOLE',
                'name' => [
                    'ja' => '全乳',
                    'en' => 'Whole Milk',
                    'vi' => 'Sữa nguyên kem',
                ],
                'description' => [
                    'ja' => '牛乳（全脂肪）。飲料・製菓ベースに使用。',
                    'en' => 'Full-fat cow milk. Used for beverages and baking base.',
                    'vi' => 'Sữa bò nguyên kem. Dùng cho đồ uống và làm nền bánh.',
                ],
                'yield_quantity' => 1000.0,
                'yield_unit' => 'ml',
                'calculated_cost' => 120.0,
                'allergen_codes' => ['milk'],
            ],
            [
                'sku' => 'MA-SOY-SAUCE',
                'name' => [
                    'ja' => '醤油',
                    'en' => 'Soy Sauce',
                    'vi' => 'Nước tương',
                ],
                'description' => [
                    'ja' => '日本式醤油。調味・マリネに使用。',
                    'en' => 'Japanese-style soy sauce. Used for seasoning and marinades.',
                    'vi' => 'Nước tương kiểu Nhật. Dùng để nêm nếm và ướp.',
                ],
                'yield_quantity' => 1000.0,
                'yield_unit' => 'ml',
                'calculated_cost' => 200.0,
                'allergen_codes' => ['wheat', 'soybean'],
            ],
            [
                'sku' => 'MA-BUTTER-UNSALT',
                'name' => [
                    'ja' => '無塩バター',
                    'en' => 'Unsalted Butter',
                    'vi' => 'Bơ không muối',
                ],
                'description' => [
                    'ja' => '無塩バター。製菓・ソース仕上げに使用。',
                    'en' => 'Unsalted butter. Used for baking and finishing sauces.',
                    'vi' => 'Bơ không muối. Dùng để làm bánh và hoàn thiện sốt.',
                ],
                'yield_quantity' => 100.0,
                'yield_unit' => 'g',
                'calculated_cost' => 150.0,
                'allergen_codes' => ['milk'],
            ],
            [
                'sku' => 'MA-SHRIMP-RAW',
                'name' => [
                    'ja' => '生えび',
                    'en' => 'Raw Shrimp',
                    'vi' => 'Tôm sống',
                ],
                'description' => [
                    'ja' => '殻付き生えび。炒め物・スープに使用。',
                    'en' => 'Shell-on raw shrimp. Used for stir-fries and soups.',
                    'vi' => 'Tôm sống còn vỏ. Dùng cho món xào và canh.',
                ],
                'yield_quantity' => 100.0,
                'yield_unit' => 'g',
                'calculated_cost' => 350.0,
                'allergen_codes' => ['shrimp'],
            ],
            [
                'sku' => 'MA-SUGAR-WHITE',
                'name' => [
                    'ja' => '上白糖',
                    'en' => 'White Sugar',
                    'vi' => 'Đường trắng',
                ],
                'description' => [
                    'ja' => '日本式上白糖。製菓・調味に使用。',
                    'en' => 'Japanese-style white sugar. Used in baking and seasoning.',
                    'vi' => 'Đường trắng kiểu Nhật. Dùng trong làm bánh và nêm nếm.',
                ],
                'yield_quantity' => 1000.0,
                'yield_unit' => 'g',
                'calculated_cost' => 90.0,
                'allergen_codes' => [],
            ],
            [
                // Plan-022 demo — raw fruit, no allergens. Used as input
                // for the "Passion Fruit Sauce" produced material defined in
                // Plan022ComprehensiveDemoSeeder so the recipe seed has a
                // real fruit material to consume.
                'sku' => 'MA-PASSION-FRUIT',
                'name' => [
                    'ja' => 'パッションフルーツ',
                    'en' => 'Passion Fruit',
                    'vi' => 'Chanh leo',
                ],
                'description' => [
                    'ja' => '新鮮なパッションフルーツ。ソース・デザートに使用。',
                    'en' => 'Fresh passion fruit. Used for sauces and desserts.',
                    'vi' => 'Chanh leo tươi. Dùng làm sốt và món tráng miệng.',
                ],
                'yield_quantity' => 100.0,
                'yield_unit' => 'g',
                'calculated_cost' => 220.0,
                'allergen_codes' => [],
            ],
            [
                'sku' => 'MA-RICE-JAPANESE',
                'name' => [
                    'ja' => '日本米',
                    'en' => 'Japanese Short-Grain Rice',
                    'vi' => 'Gạo Nhật hạt ngắn',
                ],
                'description' => [
                    'ja' => '国産うるち米。ご飯・リゾットに使用。',
                    'en' => 'Domestic Japanese short-grain rice. Used for steamed rice and risotto.',
                    'vi' => 'Gạo hạt ngắn Nhật nội địa. Dùng để nấu cơm và risotto.',
                ],
                'yield_quantity' => 1000.0,
                'yield_unit' => 'g',
                'calculated_cost' => 180.0,
                'allergen_codes' => [],
            ],
        ];
    }
}
