<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\DisposalRecord;
use App\Models\Material;
use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Omnify\Enums\MaterialLotSourceEnum;
use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Omnify\Enums\StockTransactionSubTypeEnum;
use App\Omnify\Enums\StockTransactionTypeEnum;
use App\Services\Inventory\MaterialBatchService;
use App\Services\Inventory\MaterialLotService;
use App\Services\Inventory\StockTransactionService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Plan-022 Comprehensive Demo Seeder
 *
 * Goal — let a new operator open the app and see EVERY state of the inventory
 * + production domain on one demo brand, in one fresh seed run. Where
 * MaterialSeeder gives a flat list of raw materials, this seeder layers on top:
 *
 *   • 2 warehouses (Main + Cold Storage)  → exercises transfer + cold-chain
 *   • MaterialUnits (base + secondary)    → exercises unit conversion
 *   • Cold-chain CCP flag                 → exercises temperature gating
 *   • 3 PRODUCED materials with Recipes   → exercises HQ approval workflow
 *   • Recipes in every ApprovalStatus     → draft / pending / approved / rejected
 *   • Inbound lots in every MaterialLotStatus → active / quarantined / expired
 *                                          / depleted / disposed
 *   • One MaterialBatch per status        → draft / pending / approved /
 *                                          in_progress / completed / cancelled
 *     (the "completed" batch mints a production lot — so we also cover
 *      MaterialLotSourceEnum::Production)
 *   • Adjustment in/out + disposal        → exercises StockTransaction sub-types
 *
 * Scope is intentionally ONE brand (the first active brand) so the dataset
 * stays dense and navigable on a demo screen. Use `migrate:fresh --seed` to
 * get the full picture; or run this seeder standalone after MaterialSeeder:
 *
 *   docker compose exec -T app php artisan db:seed --class=Plan022ComprehensiveDemoSeeder
 *
 * Idempotent — re-running detects an existing demo warehouse via its `code`
 * marker and skips. Wipe with `migrate:fresh --seed` if you want a clean reset.
 */
class Plan022ComprehensiveDemoSeeder extends Seeder
{
    use RefusesToRunInProduction;
    use WithoutModelEvents;

    private const DEMO_WAREHOUSE_MAIN = 'DEMO-MAIN';

    private const DEMO_WAREHOUSE_COLD = 'DEMO-COLD';

    private string $orgId;

    private Brand $brand;

    private Warehouse $mainWh;

    private Warehouse $coldWh;

    private string $actorId;

    public function run(
        MaterialLotService $lotService,
        MaterialBatchService $batchService,
        StockTransactionService $txnService,
    ): void {
        $this->guardAgainstProduction();

        $org = Organization::first();
        if (! $org) {
            $this->command?->warn('Plan022Demo skipped: no Organization seeded — run MockDataSeeder first.');

            return;
        }
        $this->orgId = (string) $org->id;

        $brand = Brand::where('console_organization_id', $org->console_organization_id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first();
        if (! $brand) {
            $this->command?->warn('Plan022Demo skipped: no active Brand for the seeded Organization.');

            return;
        }
        $this->brand = $brand;

        // Already seeded? Bail out — fresh seed wipes via migrate:fresh.
        $marker = Warehouse::where('organization_id', $this->orgId)
            ->where('code', self::DEMO_WAREHOUSE_MAIN)
            ->first();
        if ($marker) {
            $this->command?->info('Plan022Demo: demo warehouse already exists — skipping (run migrate:fresh to reset).');

            return;
        }

        $actor = User::where('console_organization_id', $org->console_organization_id)->first();
        if (! $actor) {
            $this->command?->warn('Plan022Demo skipped: no User belongs to the seeded Organization.');

            return;
        }
        $this->actorId = (string) $actor->id;
        Auth::login($actor);

        $this->command?->info("Plan022Demo: seeding for brand '{$brand->slug}'…");

        // 1. Warehouses (Main + Cold Storage).
        $this->seedWarehouses();

        // 2. Pick existing raw materials (from MaterialSeeder) and beef them up.
        $rawMaterials = $this->collectRawMaterials();
        if ($rawMaterials->count() < 4) {
            $this->command?->warn('Plan022Demo: < 4 raw materials available — run MaterialSeeder first.');

            return;
        }

        // 3. MaterialUnits — base + secondary on selected materials.
        $this->seedMaterialUnits($rawMaterials);

        // 4. Flag one material as cold-chain (CCP).
        $coldChain = $rawMaterials->firstWhere('sku', 'like', '%MILK%') ?? $rawMaterials[2];
        $this->flagColdChain($coldChain);

        // 5. Produced materials (BTP) + recipes in every approval status.
        $produced = $this->seedProducedMaterialsAndRecipes($rawMaterials);

        // 6. Inbound lots in every MaterialLotStatus.
        $this->seedInboundLotsAcrossStatuses($lotService, $rawMaterials, $coldChain);

        // 7. Production batches — one per MaterialBatchStatus.
        $this->seedBatchesAcrossStatuses($batchService, $produced['pancake_mix'], $rawMaterials);

        // 8. Adjustments (in/out) + disposal transactions.
        $this->seedManualAdjustments($txnService, $rawMaterials);

        $this->printSummary();
    }

    // =========================================================================
    //  1. Warehouses
    // =========================================================================

    private function seedWarehouses(): void
    {
        // Find a branch that belongs to THIS brand (branches link via
        // console_brand_id — see MaterialLotService::assertCrossBrand).
        $branch = Branch::where('console_brand_id', $this->brand->console_brand_id)
            ->where('is_active', true)
            ->orderBy('created_at')
            ->first();

        $this->mainWh = Warehouse::create([
            'organization_id' => $this->orgId,
            'branch_id' => $branch?->id,
            'code' => self::DEMO_WAREHOUSE_MAIN,
            'name' => 'Demo Main Warehouse',
            'type' => 'main',
            'is_active' => true,
            // pending state for batches: auto_approve_batch must be OFF so
            // submit() goes to pending instead of skipping to approved.
            'auto_approve_batch' => false,
            'auto_approve_stock_in' => true,
            'auto_approve_stock_out' => true,
        ]);

        $this->coldWh = Warehouse::create([
            'organization_id' => $this->orgId,
            'branch_id' => $branch?->id,
            'code' => self::DEMO_WAREHOUSE_COLD,
            'name' => 'Demo Cold Storage',
            'type' => 'branch',
            'is_active' => true,
            'auto_approve_batch' => false,
            'auto_approve_stock_in' => true,
            'auto_approve_stock_out' => true,
        ]);

        $this->command?->info('Plan022Demo: 2 warehouses (Main + Cold).');
    }

    // =========================================================================
    //  2. Collect raw materials seeded by MaterialSeeder
    // =========================================================================

    private function collectRawMaterials(): Collection
    {
        return Material::where('organization_id', $this->orgId)
            ->where('brand_id', $this->brand->id)
            ->whereNull('yield_unit') // Plan-022 T2: raw materials carry null yield_unit
            ->orderBy('name')
            ->get();
    }

    // =========================================================================
    //  3. MaterialUnits — base + secondary
    // =========================================================================

    private function seedMaterialUnits(Collection $rawMaterials): void
    {
        // Per-SKU canonical base unit. Mirrors the original MaterialSeeder
        // material definitions (before Plan-022 T2 nulled yield_unit on the
        // material row). Every raw material referenced by a Recipe MUST have
        // its ingredient unit registered here, otherwise MaterialBatchService
        // ::complete() → calculateBaseQuantity() rejects the consumption.
        $baseBySku = [
            'WHEAT-FLOUR' => 'g',
            'EGG-WHOLE' => 'g',
            'MILK-WHOLE' => 'ml',
            'SOY-SAUCE' => 'ml',
            'BUTTER-UNSALT' => 'g',
            'SHRIMP-RAW' => 'g',
            'SUGAR-WHITE' => 'g',
            'RICE-JAPANESE' => 'g',
        ];

        $secondaryByBase = [
            'g' => ['unit' => 'kg', 'ratio' => 1000.0],
            'ml' => ['unit' => 'bottle_1l', 'ratio' => 1000.0],
        ];

        $count = 0;
        foreach ($rawMaterials as $material) {
            $base = null;
            foreach ($baseBySku as $suffix => $unit) {
                if (str_contains((string) $material->sku, $suffix)) {
                    $base = $unit;
                    break;
                }
            }
            if ($base === null) {
                continue;
            }

            // (material_id, unit) is unique, and a material carried in by the
            // catalog snapshot already declares its base unit — re-running the
            // demo top-up must not collide with it.
            MaterialUnit::updateOrCreate(
                ['material_id' => $material->id, 'unit' => $base],
                ['ratio' => 1.0, 'is_base' => true],
            );

            if (isset($secondaryByBase[$base])) {
                MaterialUnit::updateOrCreate(
                    ['material_id' => $material->id, 'unit' => $secondaryByBase[$base]['unit']],
                    ['ratio' => $secondaryByBase[$base]['ratio'], 'is_base' => false],
                );
            }

            $count++;
        }

        // Bonus: flour gets a third unit (bag_25kg) so the receive form has a
        // material with 3 registered units to demonstrate the dropdown.
        $flour = $rawMaterials->first(fn ($m) => str_contains((string) $m->sku, 'WHEAT-FLOUR'));
        if ($flour) {
            MaterialUnit::updateOrCreate(
                ['material_id' => $flour->id, 'unit' => 'bag_25kg'],
                ['ratio' => 25000.0, 'is_base' => false],
            );
        }

        $this->command?->info("Plan022Demo: attached MaterialUnits to {$count} raw materials (base + secondary, flour +bag_25kg).");
    }

    // =========================================================================
    //  4. Cold-chain CCP
    // =========================================================================

    private function flagColdChain(Material $material): void
    {
        $material->update([
            'requires_temperature_check' => true,
            'temperature_min' => -2.0,
            'temperature_max' => 4.0,
        ]);
        $this->command?->info("Plan022Demo: flagged '{$material->name}' as cold-chain (-2°C to 4°C).");
    }

    // =========================================================================
    //  5. Produced materials + recipes (every approval status)
    // =========================================================================

    /**
     * @return array<string, Material>
     */
    private function seedProducedMaterialsAndRecipes(Collection $rawMaterials): array
    {
        $flour = $rawMaterials->firstWhere('sku', 'like', '%FLOUR%') ?? $rawMaterials[0];
        $egg = $rawMaterials->firstWhere('sku', 'like', '%EGG%') ?? $rawMaterials[1];
        $milk = $rawMaterials->firstWhere('sku', 'like', '%MILK%') ?? $rawMaterials[2];
        $soy = $rawMaterials->firstWhere('sku', 'like', '%SOY%') ?? $rawMaterials[3] ?? $rawMaterials[0];
        $butter = $rawMaterials->firstWhere('sku', 'like', '%BUTTER%') ?? $rawMaterials[0];
        $shrimp = $rawMaterials->firstWhere('sku', 'like', '%SHRIMP%') ?? $rawMaterials[0];
        $sugar = $rawMaterials->firstWhere('sku', 'like', '%SUGAR%') ?? $rawMaterials[0];

        // --- 5a. Pancake Mix — APPROVED (active) + REJECTED (old) recipes.
        $pancake = $this->makeProducedMaterial(
            'DEMO-PANCAKE-MIX',
            ['ja' => 'パンケーキミックス', 'en' => 'Pancake Mix', 'vi' => 'Bột pha bánh pancake'],
            1000,
            'g',
            [
                'ja' => '小麦粉・卵・牛乳のミックス。1000g/バッチ。',
                'en' => 'Wheat flour, egg and milk blend. 1000g per batch.',
                'vi' => 'Hỗn hợp bột mì, trứng, sữa. 1000g mỗi mẻ.',
            ],
        );
        $this->makeRecipe(
            $pancake,
            ['ja' => 'パンケーキミックス v1', 'en' => 'Pancake Mix Recipe v1', 'vi' => 'Công thức bột pancake v1'],
            'approved', true,
            [
                ['type' => 'material', 'material_id' => $flour->id, 'quantity' => 500, 'unit' => 'g'],
                ['type' => 'material', 'material_id' => $egg->id, 'quantity' => 100, 'unit' => 'g'],
                ['type' => 'material', 'material_id' => $milk->id, 'quantity' => 400, 'unit' => 'ml'],
            ],
        );
        $this->makeRecipe(
            $pancake,
            ['ja' => 'パンケーキミックス v0 (却下)', 'en' => 'Pancake Mix v0 (rejected)', 'vi' => 'Bột pancake v0 (đã từ chối)'],
            'rejected', false,
            [['type' => 'material', 'material_id' => $flour->id, 'quantity' => 800, 'unit' => 'g']],
        );

        // --- 5b. Sauce Base — APPROVED v1 + DRAFT v2 (pending revision).
        $sauce = $this->makeProducedMaterial(
            'DEMO-SAUCE-BASE',
            ['ja' => 'ソースベース', 'en' => 'Sauce Base', 'vi' => 'Nền sốt'],
            500,
            'ml',
        );
        $this->makeRecipe(
            $sauce,
            ['ja' => 'ソースベース v1', 'en' => 'Sauce Base v1', 'vi' => 'Nền sốt v1'],
            'approved', true,
            [
                ['type' => 'material', 'material_id' => $soy->id, 'quantity' => 200, 'unit' => 'ml'],
                ['type' => 'material', 'material_id' => $butter->id, 'quantity' => 50, 'unit' => 'g'],
                ['type' => 'material', 'material_id' => $sugar->id, 'quantity' => 30, 'unit' => 'g'],
            ],
        );
        $this->makeRecipe(
            $sauce,
            ['ja' => 'ソースベース v2 (草案)', 'en' => 'Sauce Base v2 (draft revision)', 'vi' => 'Nền sốt v2 (bản nháp)'],
            'draft', true,
            [
                ['type' => 'material', 'material_id' => $soy->id, 'quantity' => 180, 'unit' => 'ml'],
                ['type' => 'material', 'material_id' => $butter->id, 'quantity' => 60, 'unit' => 'g'],
            ],
        );

        // --- 5c. Tempura Shrimp — PENDING (awaiting approval).
        $tempura = $this->makeProducedMaterial(
            'DEMO-TEMPURA-SHRIMP',
            ['ja' => 'えびの天ぷら', 'en' => 'Tempura Shrimp', 'vi' => 'Tôm chiên tempura'],
            200,
            'g',
        );
        $this->makeRecipe(
            $tempura,
            ['ja' => 'えびの天ぷら v1', 'en' => 'Tempura Shrimp v1', 'vi' => 'Tôm tempura v1'],
            'pending', true,
            [
                ['type' => 'material', 'material_id' => $shrimp->id, 'quantity' => 150, 'unit' => 'g'],
                ['type' => 'material', 'material_id' => $flour->id, 'quantity' => 40, 'unit' => 'g'],
            ],
        );

        // --- 5d. Passion Fruit Sauce — APPROVED. Vai trò: ví dụ minh hoạ
        // "raw chanh leo → produced sốt chanh leo" mà luồng UI hướng dẫn người
        // dùng đầu tiên cảm nhận được. Cần raw material `MA-PASSION-FRUIT`
        // được seed bởi MaterialSeeder (SKU đã bị prefix theo brand slug).
        $brandSkuPrefix = strtoupper(preg_replace('/[^a-z0-9]/i', '', $this->brand->slug) ?: 'B');
        $passion = Material::query()
            ->where('organization_id', $this->orgId)
            ->where('brand_id', $this->brand->id)
            ->where('sku', "{$brandSkuPrefix}-MA-PASSION-FRUIT")
            ->first();

        if ($passion) {
            $passionSauce = $this->makeProducedMaterial(
                'DEMO-PASSION-SAUCE',
                ['ja' => 'パッションフルーツソース', 'en' => 'Passion Fruit Sauce', 'vi' => 'Sốt chanh leo'],
                200,
                'ml',
                [
                    'ja' => 'パッションフルーツと砂糖で作るソース。デザートのトッピング。',
                    'en' => 'Sauce made from passion fruit and sugar. Dessert topping.',
                    'vi' => 'Sốt làm từ chanh leo và đường. Dùng kèm món tráng miệng.',
                ],
            );
            $this->makeRecipe(
                $passionSauce,
                [
                    'ja' => 'パッションフルーツソース v1',
                    'en' => 'Passion Fruit Sauce v1',
                    'vi' => 'Sốt chanh leo v1',
                ],
                'approved', true,
                [
                    ['type' => 'material', 'material_id' => $passion->id, 'quantity' => 500, 'unit' => 'g'],
                    ['type' => 'material', 'material_id' => $sugar->id, 'quantity' => 100, 'unit' => 'g'],
                ],
                [
                    'ja' => 'パッションフルーツ500g + 砂糖100g → 200ml仕上がり。',
                    'en' => '500g passion fruit + 100g sugar → 200ml yield.',
                    'vi' => '500g chanh leo + 100g đường → 200ml thành phẩm.',
                ],
            );
            $producedCount = 4;
            $recipeCount = 6;
        } else {
            $this->command?->warn('Plan022Demo: passion fruit raw material not found — skipped sauce recipe.');
            $producedCount = 3;
            $recipeCount = 5;
        }

        $this->command?->info(sprintf(
            'Plan022Demo: %d produced materials, %d recipes (approved/draft/pending/rejected mix).',
            $producedCount,
            $recipeCount,
        ));

        return [
            'pancake_mix' => $pancake,
            'sauce_base' => $sauce,
            'tempura_shrimp' => $tempura,
        ];
    }

    /**
     * Plan-022 — multi-locale produced material seed. Accepts either a
     * flat string `$name` (back-compat — assigned to default locale only)
     * or an `[ja, en, vi]` array. Same for `$description`. Astrotomic
     * writes one MaterialTranslation row per locale key, so the resulting
     * material renders correctly under every Accept-Language header.
     *
     * @param  string|array{ja?: string, en?: string, vi?: string}  $name
     * @param  string|array{ja?: string, en?: string, vi?: string}|null  $description
     */
    private function makeProducedMaterial(
        string $sku,
        string|array $name,
        float $yieldQty,
        string $yieldUnit,
        string|array|null $description = null,
    ): Material {
        $names = is_array($name) ? $name : ['ja' => $name, 'en' => $name, 'vi' => $name];
        $descs = $description === null
            ? null
            : (is_array($description)
                ? $description
                : ['ja' => $description, 'en' => $description, 'vi' => $description]);
        $primary = $names['ja'] ?? $names['en'] ?? $names['vi'] ?? $sku;

        $payload = [
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'sku' => $sku,
            'name:ja' => $names['ja'] ?? $primary,
            'name:en' => $names['en'] ?? $primary,
            'name:vi' => $names['vi'] ?? $primary,
            'yield_quantity' => $yieldQty,
            'yield_unit' => $yieldUnit,
            'calculated_cost' => 0,
            'is_active' => true,
        ];
        if ($descs !== null) {
            $payload['description:ja'] = $descs['ja'] ?? null;
            $payload['description:en'] = $descs['en'] ?? null;
            $payload['description:vi'] = $descs['vi'] ?? null;
        } else {
            $payload['description:ja'] = "Plan-022 demo BTP — {$primary}";
            $payload['description:en'] = "Plan-022 demo BTP — {$primary}";
            $payload['description:vi'] = "Plan-022 demo BTP — {$primary}";
        }

        $material = Material::create($payload);
        $this->flushTranslations($material);

        // Register the yield_unit as the base MaterialUnit. Required by
        // MaterialBatchService::complete() → calculateBaseQuantity(), which
        // refuses to convert when the unit isn't registered for the material.
        MaterialUnit::create([
            'material_id' => $material->id,
            'unit' => $yieldUnit,
            'ratio' => 1.0,
            'is_base' => true,
        ]);

        return $material;
    }

    /**
     * Plan-022 — multi-locale recipe seed. `$name` and `$description` accept
     * a flat string OR an `[ja, en, vi]` map; Astrotomic writes one
     * RecipeTranslation row per locale.
     *
     * @param  string|array{ja?: string, en?: string, vi?: string}  $name
     * @param  string|array{ja?: string, en?: string, vi?: string}|null  $description
     * @param  array<int, array<string, mixed>>  $ingredients
     */
    private function makeRecipe(
        Material $material,
        string|array $name,
        string $status,
        bool $isActive,
        array $ingredients,
        string|array|null $description = null,
    ): Recipe {
        $names = is_array($name) ? $name : ['ja' => $name, 'en' => $name, 'vi' => $name];
        $descs = $description === null
            ? null
            : (is_array($description)
                ? $description
                : ['ja' => $description, 'en' => $description, 'vi' => $description]);
        $primary = $names['ja'] ?? $names['en'] ?? $names['vi'] ?? 'Recipe';

        $payload = [
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'sku' => 'R-'.strtoupper(Str::random(6)),
            'name:ja' => $names['ja'] ?? $primary,
            'name:en' => $names['en'] ?? $primary,
            'name:vi' => $names['vi'] ?? $primary,
            'material_id' => $material->id,
            'output_quantity' => $material->yield_quantity,
            'output_unit' => $material->yield_unit,
            'ingredients' => $ingredients,
            'is_active' => $isActive,
            'approval_status' => $status,
            'approved_by_id' => $status === 'approved' ? $this->actorId : null,
            'approved_at' => $status === 'approved' ? now() : null,
            'rejected_by_id' => $status === 'rejected' ? $this->actorId : null,
            'rejected_at' => $status === 'rejected' ? now() : null,
            'rejection_reason' => $status === 'rejected' ? 'demo: yield ratio too high, retest required' : null,
        ];
        if ($descs !== null) {
            $payload['description:ja'] = $descs['ja'] ?? null;
            $payload['description:en'] = $descs['en'] ?? null;
            $payload['description:vi'] = $descs['vi'] ?? null;
        }

        $recipe = Recipe::create($payload);
        $this->flushTranslations($recipe);

        return $recipe;
    }

    /**
     * Plan-022 — flush Astrotomic translation rows that the `saving` event
     * hook would normally persist for us. Required because every seeder
     * file uses `WithoutModelEvents`. Mirrors `MaterialService::flushTranslations`
     * but generic (works for Material AND Recipe — both Astrotomic-Translatable).
     */
    private function flushTranslations(Model $model): void
    {
        if (! method_exists($model, 'getTranslationRelationKey')) {
            return;
        }

        /** @var iterable<Model> $translations */
        $translations = $model->translations;
        foreach ($translations as $translation) {
            if (! $translation->exists || $translation->isDirty()) {
                if (! empty($connectionName = $model->getConnectionName())) {
                    $translation->setConnection($connectionName);
                }
                $translation->setAttribute(
                    $model->getTranslationRelationKey(),
                    $model->getKey()
                );
                $translation->save();
            }
        }
    }

    // =========================================================================
    //  6. Inbound lots — every MaterialLotStatus
    // =========================================================================

    private function seedInboundLotsAcrossStatuses(
        MaterialLotService $lotService,
        Collection $rawMaterials,
        Material $coldChain,
    ): void {
        $suppliers = ['日本製粉', 'メイトーフード', 'カゴメ', '味の素'];
        $created = 0;

        foreach ($rawMaterials->take(4)->values() as $idx => $material) {
            $supplier = $suppliers[$idx];

            // 6a. ACTIVE — fresh, 90d expiry.
            $this->receiveLot($lotService, $material, $supplier, 5000, +90, $coldChain);

            // 6b. ACTIVE — near-expiry, 5d.
            $this->receiveLot($lotService, $material, $supplier, 1000, +5, $coldChain);

            // 6c. EXPIRED — already expired, flip status to expired.
            $expiredLot = $this->receiveLot($lotService, $material, $supplier, 500, -3, $coldChain);
            if ($expiredLot) {
                $expiredLot->update(['status' => MaterialLotStatusEnum::Expired->value]);
            }

            // 6d. QUARANTINED — fresh stock pending QA.
            $quarantineLot = $this->receiveLot($lotService, $material, $supplier, 800, +60, $coldChain);
            if ($quarantineLot) {
                $lotService->quarantine($quarantineLot, 'demo: pending QA result');
            }

            // 6e. DEPLETED — manually drain qty_on_hand to 0 + flip status.
            $depletedLot = $this->receiveLot($lotService, $material, $supplier, 200, +30, $coldChain);
            if ($depletedLot) {
                $depletedLot->update([
                    'qty_on_hand' => 0,
                    'status' => MaterialLotStatusEnum::Depleted->value,
                ]);
            }

            // 6f. DISPOSED — receive then dispose (creates a DisposalRecord).
            $disposeLot = $this->receiveLot($lotService, $material, $supplier, 300, +10, $coldChain);
            if ($disposeLot) {
                try {
                    $lotService->dispose($disposeLot, force: true);
                    // Best-effort DisposalRecord for the disposal list page.
                    DisposalRecord::create([
                        'organization_id' => $this->orgId,
                        'material_lot_id' => $disposeLot->id,
                        'warehouse_id' => $disposeLot->warehouse_id,
                        'disposed_qty' => 300,
                        'unit' => $disposeLot->unit,
                        'reason' => 'demo: cosmetic damage during unload',
                        'disposed_by_id' => $this->actorId,
                        'disposed_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    // DisposalRecord schema may differ across versions — non-fatal.
                }
            }

            $created += 6;
        }

        $this->command?->info("Plan022Demo: received {$created} inbound lots across 5 statuses.");
    }

    private function receiveLot(
        MaterialLotService $lotService,
        Material $material,
        string $supplier,
        float $qty,
        int $daysToExpiry,
        Material $coldChain,
    ): ?MaterialLot {
        $unit = $material->materialUnits()->where('is_base', true)->value('unit')
            ?? $material->materialUnits()->value('unit')
            ?? 'unit';

        $payload = [
            'organization_id' => $this->orgId,
            'material_id' => $material->id,
            'warehouse_id' => $this->mainWh->id,
            'supplier_name' => $supplier,
            'supplier_lot_code' => sprintf('SUP-%s-%d', strtoupper(substr($material->sku ?? 'GEN', -4)), $daysToExpiry),
            'received_at' => now()->subDays(max(1, abs($daysToExpiry) + 2)),
            'expiry_date' => now()->addDays($daysToExpiry)->format('Y-m-d'),
            'received_qty' => $qty,
            'unit' => $unit,
            'cost_per_unit' => (float) ($material->calculated_cost ?? 100) * 0.9,
            'currency' => 'JPY',
            'created_by_id' => $this->actorId,
        ];

        if ($material->id === $coldChain->id) {
            $payload['received_temperature'] = 2.0; // in-range
        }

        try {
            $result = $lotService->receive($payload);

            return $result['lot'];
        } catch (\Throwable $e) {
            $this->command?->warn("Plan022Demo: lot receive skipped for {$material->sku}: {$e->getMessage()}");

            return null;
        }
    }

    // =========================================================================
    //  7. MaterialBatches — every status
    // =========================================================================

    private function seedBatchesAcrossStatuses(
        MaterialBatchService $batchService,
        Material $producedMaterial,
        Collection $rawMaterials,
    ): void {
        // 7a. DRAFT
        $this->makeBatch($batchService, $producedMaterial, 'draft');

        // 7b. PENDING (submit only — warehouse.auto_approve_batch=false)
        $pending = $this->makeBatch($batchService, $producedMaterial, 'draft');
        if ($pending) {
            try {
                $batchService->submit($pending->fresh());
            } catch (\Throwable $e) {
                $this->command?->warn("Plan022Demo: submit-to-pending failed: {$e->getMessage()}");
            }
        }

        // 7c. APPROVED
        $approved = $this->makeBatch($batchService, $producedMaterial, 'draft');
        if ($approved) {
            try {
                $approved = $batchService->submit($approved->fresh());
                if (($approved->status->value ?? $approved->status) === 'pending') {
                    $batchService->approve($approved->fresh(), $this->actorId);
                }
            } catch (\Throwable $e) {
                $this->command?->warn("Plan022Demo: approve failed: {$e->getMessage()}");
            }
        }

        // 7d. IN_PROGRESS
        $running = $this->makeBatch($batchService, $producedMaterial, 'draft');
        if ($running) {
            try {
                $running = $batchService->submit($running->fresh());
                if (($running->status->value ?? $running->status) === 'pending') {
                    $running = $batchService->approve($running->fresh(), $this->actorId);
                }
                $batchService->start($running->fresh());
            } catch (\Throwable $e) {
                $this->command?->warn("Plan022Demo: start failed: {$e->getMessage()}");
            }
        }

        // 7e. COMPLETED — full happy path, mints a production output lot.
        $completed = $this->makeBatch($batchService, $producedMaterial, 'draft');
        if ($completed) {
            try {
                $completed = $batchService->submit($completed->fresh());
                if (($completed->status->value ?? $completed->status) === 'pending') {
                    $completed = $batchService->approve($completed->fresh(), $this->actorId);
                }
                $completed = $batchService->start($completed->fresh());
                // 950 against a 1000 baseline is a 5% under-yield, and the
                // recipe tolerance defaults to 0 — a reason is mandatory or the
                // completion throws and the demo never mints its output lot.
                $batchService->complete($completed->fresh(), $this->actorId, 950.0, 'demo: 5% spoilage during cooking');
            } catch (\Throwable $e) {
                $this->command?->warn("Plan022Demo: complete failed: {$e->getMessage()}");
            }
        }

        // 7f. CANCELLED — start a fresh draft and immediately cancel.
        $cancelled = $this->makeBatch($batchService, $producedMaterial, 'draft');
        if ($cancelled && method_exists($batchService, 'cancel')) {
            try {
                $batchService->cancel($cancelled->fresh(), 'demo: operator cancelled');
            } catch (\Throwable $e) {
                $this->command?->warn("Plan022Demo: cancel failed: {$e->getMessage()}");
            }
        }

        $this->command?->info('Plan022Demo: seeded 6 batches across draft/pending/approved/in_progress/completed/cancelled.');
    }

    private function makeBatch(
        MaterialBatchService $batchService,
        Material $producedMaterial,
        string $initialStatus,
    ): ?MaterialBatch {
        try {
            return $batchService->create([
                'organization_id' => $this->orgId,
                'warehouse_id' => $this->mainWh->id,
                'material_id' => $producedMaterial->id,
                'multiplier' => 1.0,
                'planned_yield' => (float) $producedMaterial->yield_quantity,
                'yield_unit' => $producedMaterial->yield_unit,
                'note' => 'Plan-022 demo batch',
                'created_by_id' => $this->actorId,
            ]);
        } catch (\Throwable $e) {
            $this->command?->warn("Plan022Demo: batch create failed: {$e->getMessage()}");

            return null;
        }
    }

    // =========================================================================
    //  8. Manual adjustments + disposal transactions
    // =========================================================================

    private function seedManualAdjustments(
        StockTransactionService $txnService,
        Collection $rawMaterials,
    ): void {
        $material = $rawMaterials->first();
        if (! $material) {
            return;
        }
        $unit = $material->materialUnits()->where('is_base', true)->value('unit')
            ?? 'unit';

        // 8a. Adjustment IN (stock count found extra qty)
        try {
            $adjIn = $txnService->create([
                'organization_id' => $this->orgId,
                'warehouse_id' => $this->mainWh->id,
                'type' => StockTransactionTypeEnum::StockIn->value,
                'sub_type' => StockTransactionSubTypeEnum::AdjustmentIn->value,
                'note' => 'demo: stock count surplus',
                'created_by_id' => $this->actorId,
                'items' => [[
                    'material_id' => $material->id,
                    'quantity' => 50,
                    'unit' => $unit,
                ]],
            ]);
            $txnService->submit($adjIn);
        } catch (\Throwable $e) {
            $this->command?->warn("Plan022Demo: adj-in failed: {$e->getMessage()}");
        }

        // 8b. Adjustment OUT (stock count found shortage)
        try {
            $adjOut = $txnService->create([
                'organization_id' => $this->orgId,
                'warehouse_id' => $this->mainWh->id,
                'type' => StockTransactionTypeEnum::StockOut->value,
                'sub_type' => StockTransactionSubTypeEnum::AdjustmentOut->value,
                'note' => 'demo: stock count shortage',
                'created_by_id' => $this->actorId,
                'items' => [[
                    'material_id' => $material->id,
                    'quantity' => 20,
                    'unit' => $unit,
                ]],
            ]);
            $txnService->submit($adjOut);
        } catch (\Throwable $e) {
            $this->command?->warn("Plan022Demo: adj-out failed: {$e->getMessage()}");
        }

        $this->command?->info('Plan022Demo: 2 manual adjustments (in + out).');
    }

    // =========================================================================
    //  Summary
    // =========================================================================

    private function printSummary(): void
    {
        $lotCounts = MaterialLot::query()
            ->where('organization_id', $this->orgId)
            ->whereIn('warehouse_id', [$this->mainWh->id, $this->coldWh->id])
            ->selectRaw('status, source, COUNT(*) as c')
            ->groupBy('status', 'source')
            ->get();

        $batchCounts = MaterialBatch::query()
            ->where('organization_id', $this->orgId)
            ->where('warehouse_id', $this->mainWh->id)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->get();

        $recipeCounts = Recipe::query()
            ->where('organization_id', $this->orgId)
            ->where('brand_id', $this->brand->id)
            ->selectRaw('approval_status, COUNT(*) as c')
            ->groupBy('approval_status')
            ->get();

        $txnCount = StockTransaction::where('organization_id', $this->orgId)
            ->where('warehouse_id', $this->mainWh->id)
            ->count();

        $this->command?->info('────────── Plan-022 Demo Summary ──────────');
        $this->command?->info("Brand: {$this->brand->slug}");
        $this->command?->info("Warehouses: {$this->mainWh->code}, {$this->coldWh->code}");
        $unwrap = fn ($v) => is_object($v) && property_exists($v, 'value') ? $v->value : (string) $v;
        $this->command?->info('Lots by status/source:');
        foreach ($lotCounts as $row) {
            $this->command?->info('  - '.$unwrap($row->status).' / '.$unwrap($row->source).": {$row->c}");
        }
        $this->command?->info('Batches by status:');
        foreach ($batchCounts as $row) {
            $this->command?->info('  - '.$unwrap($row->status).": {$row->c}");
        }
        $this->command?->info('Recipes by approval status:');
        foreach ($recipeCounts as $row) {
            $this->command?->info('  - '.$unwrap($row->approval_status).": {$row->c}");
        }
        $this->command?->info("Stock transactions (main warehouse): {$txnCount}");
        $this->command?->info('────────────────────────────────────────────');
    }
}
