<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialUnit;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * RecipeSeeder
 *
 * Layers produced materials + recipes onto every brand that already has the
 * canonical raw materials from MaterialSeeder but no recipes yet.
 * Plan022ComprehensiveDemoSeeder covers only the first active brand; sibling
 * brands (beto-kitchen, betoya, etc.) were left with empty recipe lists, which
 * broke the production-batch flow that needs a produced material with an
 * approved recipe before it can ship.
 *
 * Recipes are derived from the raw materials seeded by MaterialSeeder (matched
 * by SKU stem, since MaterialSeeder prefixes the brand slug). Three produced
 * materials are created per brand: Pancake Mix, Sauce Base, and Passion Fruit
 * Sauce — each with a single approved + active recipe.
 *
 * Idempotent — a brand is skipped if it already has any recipe row.
 */
class RecipeSeeder extends Seeder
{
    use WithoutModelEvents;

    private const RAW_SKU_STEMS = [
        'flour' => 'MA-WHEAT-FLOUR',
        'egg' => 'MA-EGG-WHOLE',
        'milk' => 'MA-MILK-WHOLE',
        'soy' => 'MA-SOY-SAUCE',
        'butter' => 'MA-BUTTER-UNSALT',
        'sugar' => 'MA-SUGAR-WHITE',
        'passion' => 'MA-PASSION-FRUIT',
    ];

    public function run(): void
    {
        $brandsSeeded = 0;
        $recipesCreated = 0;

        foreach (Brand::where('is_active', true)->orderBy('created_at')->get() as $brand) {
            $existing = Recipe::where('brand_id', $brand->id)->count();
            if ($existing > 0) {
                $this->command?->info("RecipeSeeder: brand '{$brand->slug}' already has {$existing} recipe(s) — skipping.");

                continue;
            }

            $org = Organization::where('console_organization_id', $brand->console_organization_id)->first();
            if (! $org) {
                $this->command?->warn("RecipeSeeder: no Organization for brand '{$brand->slug}' — skipping.");

                continue;
            }

            $actor = User::where('console_organization_id', $org->console_organization_id)->first();
            if (! $actor) {
                $this->command?->warn("RecipeSeeder: no actor User for brand '{$brand->slug}' — skipping.");

                continue;
            }

            $raws = $this->collectRaws($brand->id);
            if ($raws->count() < 4) {
                $this->command?->warn("RecipeSeeder: brand '{$brand->slug}' has <4 raw materials — skipping.");

                continue;
            }

            Auth::login($actor);
            $this->command?->info("RecipeSeeder: seeding recipes for brand '{$brand->slug}'…");

            $created = $this->seedRecipesForBrand($brand, (string) $org->id, (string) $actor->id, $raws);
            $brandsSeeded++;
            $recipesCreated += $created;
            Auth::logout();
        }

        $this->command?->info(sprintf(
            'RecipeSeeder: %d brand(s) seeded, %d recipe(s) created.',
            $brandsSeeded,
            $recipesCreated,
        ));
    }

    /**
     * Pull every raw material on the brand and key it by stem so callers can
     * reach for `$raws['flour']` without re-scanning the SKU column.
     *
     * @return Collection<string, Material>
     */
    private function collectRaws(string $brandId): Collection
    {
        $rows = Material::where('brand_id', $brandId)
            ->whereNull('yield_unit')
            ->get();

        $byStem = collect();
        foreach (self::RAW_SKU_STEMS as $key => $stem) {
            $match = $rows->first(fn ($m) => str_ends_with($m->sku, $stem));
            if ($match) {
                $byStem[$key] = $match;
            }
        }

        return $byStem;
    }

    /**
     * @param  Collection<string, Material>  $raws
     */
    private function seedRecipesForBrand(Brand $brand, string $orgId, string $actorId, Collection $raws): int
    {
        $count = 0;

        // --- Pancake Mix (1000g/batch) — flour + egg + milk.
        if ($raws->has('flour') && $raws->has('egg') && $raws->has('milk')) {
            $pancake = $this->makeProducedMaterial(
                $brand, $orgId, 'DEMO-PANCAKE-MIX', 1000, 'g',
                ['ja' => 'パンケーキミックス', 'en' => 'Pancake Mix', 'vi' => 'Bột pha bánh pancake'],
                [
                    'ja' => '小麦粉・卵・牛乳のミックス。1000g/バッチ。',
                    'en' => 'Wheat flour, egg and milk blend. 1000g per batch.',
                    'vi' => 'Hỗn hợp bột mì, trứng, sữa. 1000g mỗi mẻ.',
                ],
            );
            $this->makeRecipe(
                $brand, $orgId, $actorId, $pancake, 'approved',
                ['ja' => 'パンケーキミックス v1', 'en' => 'Pancake Mix Recipe v1', 'vi' => 'Công thức bột pancake v1'],
                [
                    ['type' => 'material', 'material_id' => $raws['flour']->id, 'quantity' => 500, 'unit' => 'g'],
                    ['type' => 'material', 'material_id' => $raws['egg']->id, 'quantity' => 100, 'unit' => 'g'],
                    ['type' => 'material', 'material_id' => $raws['milk']->id, 'quantity' => 400, 'unit' => 'ml'],
                ],
            );
            $count++;
        }

        // --- Sauce Base (500ml/batch) — soy + butter + sugar.
        if ($raws->has('soy') && $raws->has('butter') && $raws->has('sugar')) {
            $sauce = $this->makeProducedMaterial(
                $brand, $orgId, 'DEMO-SAUCE-BASE', 500, 'ml',
                ['ja' => 'ソースベース', 'en' => 'Sauce Base', 'vi' => 'Nền sốt'],
            );
            $this->makeRecipe(
                $brand, $orgId, $actorId, $sauce, 'approved',
                ['ja' => 'ソースベース v1', 'en' => 'Sauce Base v1', 'vi' => 'Nền sốt v1'],
                [
                    ['type' => 'material', 'material_id' => $raws['soy']->id, 'quantity' => 200, 'unit' => 'ml'],
                    ['type' => 'material', 'material_id' => $raws['butter']->id, 'quantity' => 50, 'unit' => 'g'],
                    ['type' => 'material', 'material_id' => $raws['sugar']->id, 'quantity' => 30, 'unit' => 'g'],
                ],
            );
            $count++;
        }

        // --- Passion Fruit Sauce (200ml/batch) — passion fruit + sugar.
        if ($raws->has('passion') && $raws->has('sugar')) {
            $passionSauce = $this->makeProducedMaterial(
                $brand, $orgId, 'DEMO-PASSION-SAUCE', 200, 'ml',
                ['ja' => 'パッションフルーツソース', 'en' => 'Passion Fruit Sauce', 'vi' => 'Sốt chanh leo'],
                [
                    'ja' => 'パッションフルーツと砂糖で作るソース。デザートのトッピング。',
                    'en' => 'Sauce made from passion fruit and sugar. Dessert topping.',
                    'vi' => 'Sốt làm từ chanh leo và đường. Dùng kèm món tráng miệng.',
                ],
            );
            $this->makeRecipe(
                $brand, $orgId, $actorId, $passionSauce, 'approved',
                ['ja' => 'パッションフルーツソース v1', 'en' => 'Passion Fruit Sauce v1', 'vi' => 'Sốt chanh leo v1'],
                [
                    ['type' => 'material', 'material_id' => $raws['passion']->id, 'quantity' => 500, 'unit' => 'g'],
                    ['type' => 'material', 'material_id' => $raws['sugar']->id, 'quantity' => 100, 'unit' => 'g'],
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  array{ja: string, en: string, vi: string}  $name
     * @param  array{ja: string, en: string, vi: string}|null  $description
     */
    private function makeProducedMaterial(
        Brand $brand,
        string $orgId,
        string $skuStem,
        float $yieldQty,
        string $yieldUnit,
        array $name,
        ?array $description = null,
    ): Material {
        $brandSkuPrefix = strtoupper(preg_replace('/[^a-z0-9]/i', '', $brand->slug) ?: 'B');
        $sku = "{$brandSkuPrefix}-{$skuStem}";

        $payload = [
            'organization_id' => $orgId,
            'brand_id' => $brand->id,
            'sku' => $sku,
            'name:ja' => $name['ja'],
            'name:en' => $name['en'],
            'name:vi' => $name['vi'],
            'yield_quantity' => $yieldQty,
            'yield_unit' => $yieldUnit,
            'calculated_cost' => 0,
            'is_active' => true,
        ];
        if ($description !== null) {
            $payload['description:ja'] = $description['ja'];
            $payload['description:en'] = $description['en'];
            $payload['description:vi'] = $description['vi'];
        }

        $material = Material::create($payload);
        $this->flushTranslations($material);

        // Register the yield_unit as the base MaterialUnit so downstream
        // MaterialBatchService::complete() can convert from production qty.
        MaterialUnit::create([
            'material_id' => $material->id,
            'unit' => $yieldUnit,
            'ratio' => 1.0,
            'is_base' => true,
        ]);

        return $material;
    }

    /**
     * @param  array{ja: string, en: string, vi: string}  $name
     * @param  array<int, array<string, mixed>>  $ingredients
     */
    private function makeRecipe(
        Brand $brand,
        string $orgId,
        string $actorId,
        Material $material,
        string $status,
        array $name,
        array $ingredients,
    ): Recipe {
        $payload = [
            'organization_id' => $orgId,
            'brand_id' => $brand->id,
            'sku' => 'R-'.strtoupper(Str::random(6)),
            'name:ja' => $name['ja'],
            'name:en' => $name['en'],
            'name:vi' => $name['vi'],
            'material_id' => $material->id,
            'output_quantity' => $material->yield_quantity,
            'output_unit' => $material->yield_unit,
            'ingredients' => $ingredients,
            'is_active' => true,
            'approval_status' => $status,
            'approved_by_id' => $status === 'approved' ? $actorId : null,
            'approved_at' => $status === 'approved' ? now() : null,
        ];

        $recipe = Recipe::create($payload);
        $this->flushTranslations($recipe);

        return $recipe;
    }

    /**
     * Astrotomic's `saving` event hook is suppressed under WithoutModelEvents,
     * so translation rows queued via `name:ja` etc. don't auto-persist —
     * mirrors the helper in Plan022ComprehensiveDemoSeeder + MaterialServiceBase.
     */
    private function flushTranslations(Model $model): void
    {
        if (! method_exists($model, 'getTranslationRelationKey')) {
            return;
        }

        foreach ($model->translations as $translation) {
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
}
