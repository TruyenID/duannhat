<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ProductType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ToppingShowcaseSeeder
 * ============================================================================
 *
 * Demonstrates EVERY feature of the topping management system in a single
 * realistic Vietnamese restaurant scenario. Run this on any fresh dev brand
 * and you'll have working examples of all 8 P-tier features from issue #151
 * (now closed) — useful for:
 *
 *   • Onboarding new engineers ("how does this thing actually work?")
 *   • QA verification (hand-test customer-web flows against real data)
 *   • Demoing to product / design when discussing UX
 *   • Smoke-testing after schema changes (run + assert key invariants)
 *
 * Six scenarios — each tagged with the P-item(s) it covers:
 *
 *   1. PHO — REMOVE modifier        (P3.6: modifier_type=remove)
 *   2. BURGER + COMBO — per-product overrides + ADD modifier
 *                                    (P1.1, P1.2, P2.4, P3.5)
 *   3. PIZZA — free_up_to_n combo   (P3.8: price_strategy=free_up_to_n)
 *   4. CAFÉ — morning topping add-ons (scheduling deferred to Phase 2)
 *   5. SPICE LEVEL — single-select  (P2.4: selection_type=single)
 *   6. ICE — is_default checked     (P2.3: is_default=true)
 *
 * NOTE: scenario 5 originally demonstrated Size S/M/L. That was retired
 * because Size is semantically a Product VARIANT (lives on ProductSku via
 * option_values), not a topping. Spice level fits the topping pattern
 * better — see the inline comment at SCENARIO 5 for the full reasoning.
 *
 * Usage:
 *   docker compose exec app php artisan db:seed --class=ToppingShowcaseSeeder
 *
 * Idempotent? **No** — running twice creates duplicate groups (same brand+name
 * isn't unique). Run on a fresh DB or wipe topping_* tables first.
 *
 * Why no factories? Factories produce random data. The whole point of this
 * seeder is to produce KNOWN data that demos each feature exactly. Future
 * tests assert specific values back; random factories would defeat that.
 *
 * ============================================================================
 */
class ToppingShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        // ─── Setup: brand + topping product type ────────────────────────────
        // Pick the first brand on the system. In real onboarding this would be
        // the team's primary dev brand. If none exists, the seeder bails — the
        // assumption is the parent DemoSeeder / LocalDevSeeder already ran.
        $brand = Brand::first();
        if (! $brand) {
            $this->command->error('No Brand found — run LocalDevSeeder first.');

            return;
        }

        $orgId = $brand->organization_id ?? Organization::first()?->id;

        // The topping invariant (P1.2): only Products whose ProductType.code
        // is 'topping' may sit in a ToppingGroupItem. Create the type once.
        $toppingType = ProductType::firstOrCreate(
            ['brand_id' => $brand->id, 'code' => 'topping'],
            [
                'organization_id' => $orgId,
                'name' => 'Topping',
                'product_form' => 'physical',
            ],
        );

        // Standard "food" type for the main dishes (Pho, Burger, etc).
        $foodType = ProductType::firstOrCreate(
            ['brand_id' => $brand->id, 'code' => 'food'],
            [
                'organization_id' => $orgId,
                'name' => 'Food',
                'product_form' => 'physical',
            ],
        );

        // Helper closure — every Product needs at least one default SKU so
        // OrderItemTopping (Phase 2) can resolve product_sku_id from
        // ProductService::create's auto-default-SKU pattern. Manually mimic
        // that here since the seeder bypasses the service.
        $makeProduct = function (string $name, ProductType $type) use ($brand, $orgId): Product {
            // Slug must be unique per brand. Use the name + a short random
            // suffix so re-runs don't collide; in practice the seeder is
            // expected to run on a clean DB.
            $product = Product::create([
                'brand_id' => $brand->id,
                'organization_id' => $orgId,
                'product_type_id' => $type->id,
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::random(4),
            ]);

            // Default SKU — needed so ToppingGroupItemSku can reference a real
            // SKU for variant-aware toppings, and so Phase 2's order flow can
            // resolve product_sku_id without crashing.
            ProductSku::create([
                'product_id' => $product->id,
                'sku' => strtoupper(substr(preg_replace('/[^a-z]/i', '', $name), 0, 3)).'-DEFAULT-'.Str::random(4),
                'name' => $name,
                'option_signature' => '||',
            ]);

            return $product;
        };

        $this->command->info('=== Building topping showcase for brand: '.$brand->name.' ===');

        // =====================================================================
        // SCENARIO 1 — PHO with REMOVE modifier ("không hành, không cay")
        // =====================================================================
        // Restaurant: Vietnamese pho shop. Customer base often customizes by
        // SUBTRACTION — "no green onion", "no cilantro", "less spicy". Cannot
        // model this as zero-priced ADD because the kitchen ticket needs to
        // show ❌ NO X in bold red so the chef doesn't miss it.
        //
        // Demonstrates: P3.6 modifier_type=remove. Items have extra_price=0
        // (you don't charge for not adding something).
        // =====================================================================
        $pho = $makeProduct('Phở Bò Tô Lớn', $foodType);

        $hanhProduct = $makeProduct('Hành lá', $toppingType);
        $rauMuiProduct = $makeProduct('Rau mùi', $toppingType);
        $otProduct = $makeProduct('Ớt cay', $toppingType);

        $phoExclusions = ToppingGroup::create([
            'brand_id' => $brand->id,
            'organization_id' => $orgId,
            'name' => 'Bỏ bớt nguyên liệu',
            'modifier_type' => 'remove',  // ← key field: REMOVE not ADD
            'selection_type' => 'multiple',
            'min_select' => 0,
            'max_select' => 3,
            'is_active' => true,
        ]);
        $this->syncTranslations($phoExclusions, ['ja' => '材料を省く', 'en' => 'Remove ingredients', 'vi' => 'Bỏ bớt nguyên liệu']);
        $this->attachItems($phoExclusions, [
            ['product' => $hanhProduct, 'extra_price' => 0],   // remove → 0₫
            ['product' => $rauMuiProduct, 'extra_price' => 0],
            ['product' => $otProduct, 'extra_price' => 0],
        ]);
        $this->attach($pho, $phoExclusions);

        // =====================================================================
        // SCENARIO 2 — BURGER + COMBO with per-product overrides
        // =====================================================================
        // The "Sauce" group has DEFAULT 0–3 sauces optional (multi-select).
        // Attached to BURGER: use defaults (customer can pick any 0–3).
        // Attached to COMBO: override min=1, max=1 (combo MUST include exactly
        //                    1 sauce — kitchen consistency requirement).
        //
        // Demonstrates: P3.5 (per-product min/max overrides), P2.4
        // (selection_type interaction), and the value of NOT cloning the group.
        // =====================================================================
        $burger = $makeProduct('Burger Phô Mai', $foodType);
        $combo = $makeProduct('Combo Burger + Khoai + Coca', $foodType);

        $tuongOt = $makeProduct('Tương ớt', $toppingType);
        $sotMayonnaise = $makeProduct('Sốt mayonnaise', $toppingType);
        $sotCay = $makeProduct('Sốt cay đặc biệt', $toppingType);

        $sauceGroup = ToppingGroup::create([
            'brand_id' => $brand->id,
            'organization_id' => $orgId,
            'name' => 'Sốt chấm',
            'modifier_type' => 'add',
            'selection_type' => 'multiple',
            'min_select' => 0,
            'max_select' => 3,    // group default — burger inherits this
            'is_active' => true,
        ]);
        $this->syncTranslations($sauceGroup, ['ja' => 'ソース', 'en' => 'Dipping sauce', 'vi' => 'Sốt chấm']);
        $this->attachItems($sauceGroup, [
            ['product' => $tuongOt, 'extra_price' => 0],          // free
            ['product' => $sotMayonnaise, 'extra_price' => 0],    // free
            ['product' => $sotCay, 'extra_price' => 5000],        // 5k upcharge
        ]);

        // Burger: attach with NO overrides → inherits 0–3 sauces from group.
        $this->attach($burger, $sauceGroup);

        // Combo: attach with overrides → must pick exactly 1 sauce.
        // Phase 2 customer-web computes effective limits as
        //   min = COALESCE(attachment.min_override, group.min) = 1
        //   max = COALESCE(attachment.max_override, group.max) = 1
        ProductToppingGroup::create([
            'product_id' => $combo->id,
            'topping_group_id' => $sauceGroup->id,
            'sort_order' => 0,
            'min_select_override' => 1,   // override: combo requires 1
            'max_select_override' => 1,   // override: combo allows max 1
        ]);

        // =====================================================================
        // SCENARIO 3 — PIZZA with free_up_to_n combo deal
        // =====================================================================
        // "Build your own pizza: 3 toppings free, 15.000₫ each after."
        // Customer naturally clusters at 3 (the threshold). The 4th+ topping
        // upsells. Phase 2 service computes the cheapest 3 as free, charges
        // the rest at extra_price.
        //
        // Demonstrates: P3.8 price_strategy=free_up_to_n + free_quantity.
        // =====================================================================
        $pizza = $makeProduct('Pizza Đặc Biệt 12"', $foodType);

        $cheese = $makeProduct('Phô mai mozzarella', $toppingType);
        $pepperoni = $makeProduct('Pepperoni', $toppingType);
        $mushroom = $makeProduct('Nấm', $toppingType);
        $olive = $makeProduct('Ô liu', $toppingType);
        $basil = $makeProduct('Húng quế', $toppingType);

        $pizzaToppings = ToppingGroup::create([
            'brand_id' => $brand->id,
            'organization_id' => $orgId,
            'name' => 'Topping pizza',
            'modifier_type' => 'add',
            'selection_type' => 'multiple',
            'min_select' => 0,
            'max_select' => null,      // unlimited
            'price_strategy' => 'free_up_to_n',
            'free_quantity' => 3,      // first 3 cheapest are free
            'is_active' => true,
        ]);
        $this->syncTranslations($pizzaToppings, ['ja' => 'ピザトッピング', 'en' => 'Pizza toppings', 'vi' => 'Topping pizza']);
        $this->attachItems($pizzaToppings, [
            ['product' => $cheese, 'extra_price' => 15000],
            ['product' => $pepperoni, 'extra_price' => 15000],
            ['product' => $mushroom, 'extra_price' => 15000],
            ['product' => $olive, 'extra_price' => 15000],
            ['product' => $basil, 'extra_price' => 15000],
        ]);
        $this->attach($pizza, $pizzaToppings);

        // =====================================================================
        // SCENARIO 4 — CAFÉ morning toppings (add-on group, multiple optional)
        // =====================================================================
        // Café offers premium toppings (whipped cream, caramel drizzle).
        // Scheduling (available_from / available_to / available_days) is deferred
        // to Phase 2 — for now the group is always active; staff can toggle
        // is_active manually during non-breakfast hours if needed.
        //
        // Demonstrates: basic ADD group with low max_select cap.
        // =====================================================================
        $latte = $makeProduct('Cà phê Latte', $foodType);

        $whippedCream = $makeProduct('Kem tươi', $toppingType);
        $caramel = $makeProduct('Caramel rưới', $toppingType);

        $morningExtras = ToppingGroup::create([
            'brand_id' => $brand->id,
            'organization_id' => $orgId,
            'name' => 'Topping buổi sáng',
            'modifier_type' => 'add',
            'selection_type' => 'multiple',
            'min_select' => 0,
            'max_select' => 2,
            'is_active' => true,
        ]);
        $this->syncTranslations($morningExtras, ['ja' => 'モーニングトッピング', 'en' => 'Morning extras', 'vi' => 'Topping buổi sáng']);
        $this->attachItems($morningExtras, [
            ['product' => $whippedCream, 'extra_price' => 8000],
            ['product' => $caramel, 'extra_price' => 5000],
        ]);
        $this->attach($latte, $morningExtras);

        // =====================================================================
        // SCENARIO 5 — Spice level single-select (radio button, exactly 1)
        // =====================================================================
        // Spice level is a genuine CHOICE — each option changes how the kitchen
        // preps the dish (chili oil quantity, fresh chili added or not, sauce
        // adjustment). customer-web renders as radio buttons. Mild has no
        // upcharge; medium/hot are 0₫ too (it's a customization, not a price
        // tier).
        //
        // ─── DESIGN NOTE — why this is NOT 'Size S/M/L' ──────────────────────
        // An earlier draft of this seeder used Size S/M/L here. That's
        // semantically wrong for our schema:
        //   • Size is a Product variant — should live on ProductSku via
        //     option_values (ProductOption "Size", values "S/M/L"), not as
        //     toppings. Customer order resolves SKU → has price + inventory
        //     tracked per size.
        //   • Toppings are ADD-ONS layered onto a chosen SKU. They share
        //     prep-time / inventory hooks, but they ARE NOT the SKU itself.
        // Mixing the two creates duplicate inventory tracking, awkward
        // recipe-link semantics, and a confused admin form. Spice level is
        // a clean fit because it's a layered modifier, not a variant.
        // ─────────────────────────────────────────────────────────────────────
        //
        // Demonstrates: P2.4 selection_type=single + cross-field
        // (selection_type=single ⇒ max_select=1 enforced at request layer).
        // =====================================================================
        $traSua = $makeProduct('Trà sữa trân châu', $foodType);
        $bunBoHue = $makeProduct('Bún bò Huế', $foodType);

        $cayNhe = $makeProduct('Cay nhẹ', $toppingType);
        $cayVua = $makeProduct('Cay vừa', $toppingType);
        $cayNhieu = $makeProduct('Cay nhiều', $toppingType);

        $spiceGroup = ToppingGroup::create([
            'brand_id' => $brand->id,
            'organization_id' => $orgId,
            'name' => 'Mức độ cay',
            'modifier_type' => 'add',
            'selection_type' => 'single',  // ← radio button UX
            'min_select' => 1,             // must pick a spice level
            'max_select' => 1,             // exactly 1 (cross-field rule enforces)
            'is_active' => true,
        ]);
        $this->syncTranslations($spiceGroup, ['ja' => '辛さレベル', 'en' => 'Spice level', 'vi' => 'Mức độ cay']);
        $this->attachItems($spiceGroup, [
            ['product' => $cayNhe, 'extra_price' => 0],
            ['product' => $cayVua, 'extra_price' => 0],
            ['product' => $cayNhieu, 'extra_price' => 0],
        ]);
        $this->attach($bunBoHue, $spiceGroup);

        // =====================================================================
        // SCENARIO 6 — Drink ICE/NO-ICE with default checked (is_default=true)
        // =====================================================================
        // VN customer convention: drinks come iced unless explicitly asked
        // otherwise. Pre-check "with ice" so customer doesn't have to pick at
        // all (90% case). Customer who wants no ice unticks the default.
        //
        // Phase 2 customer-web: if customer doesn't explicitly send a
        // selection for this group, server defaults to is_default=true items.
        //
        // Demonstrates: P2.3 is_default field on ToppingGroupItem.
        // =====================================================================
        $iceProduct = $makeProduct('Đá', $toppingType);
        $noIceProduct = $makeProduct('Không đá', $toppingType);

        $iceGroup = ToppingGroup::create([
            'brand_id' => $brand->id,
            'organization_id' => $orgId,
            'name' => 'Đá',
            'modifier_type' => 'add',
            'selection_type' => 'single',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
        ]);
        $this->syncTranslations($iceGroup, ['ja' => '氷', 'en' => 'Ice', 'vi' => 'Đá']);

        // Manually create the items so we can set is_default — the helper
        // attachItems doesn't expose that field (it's not the common case).
        $iceItem = ToppingGroupItem::create([
            'topping_group_id' => $iceGroup->id,
            'product_id' => $iceProduct->id,
            'sort_order' => 0,
            'is_default' => true,    // ← pre-checked in customer-web UI
        ]);
        ToppingGroupItemSku::create([
            'topping_group_item_id' => $iceItem->id,
            'product_sku_id' => ProductSku::where('product_id', $iceProduct->id)->orderBy('created_at')->value('id'),
            'extra_price' => 0,
        ]);

        $noIceItem = ToppingGroupItem::create([
            'topping_group_id' => $iceGroup->id,
            'product_id' => $noIceProduct->id,
            'sort_order' => 1,
            'is_default' => false,
        ]);
        ToppingGroupItemSku::create([
            'topping_group_item_id' => $noIceItem->id,
            'product_sku_id' => ProductSku::where('product_id', $noIceProduct->id)->orderBy('created_at')->value('id'),
            'extra_price' => 0,
        ]);

        $this->attach($traSua, $iceGroup);  // also attach to bubble tea

        // ─── Done ────────────────────────────────────────────────────────────
        $this->command->info('Created 6 topping groups exercising every P-tier feature:');
        $this->command->info('  1. Phở Bò       — Bỏ bớt nguyên liệu (REMOVE)');
        $this->command->info('  2. Burger+Combo — Sốt chấm (per-product overrides)');
        $this->command->info('  3. Pizza        — Topping pizza (free_up_to_n=3)');
        $this->command->info('  4. Latte        — Topping buổi sáng (morning add-ons)');
        $this->command->info('  5. Bún bò Huế   — Mức độ cay (single-select)');
        $this->command->info('  6. Trà sữa      — Đá (is_default=true)');
    }

    /**
     * Bulk-create ToppingGroupItem + a default SKU row each.
     *
     * @param  array<int, array{product: Product, extra_price: int}>  $items
     */
    private function attachItems(ToppingGroup $group, array $items): void
    {
        foreach ($items as $i => $row) {
            $item = ToppingGroupItem::create([
                'topping_group_id' => $group->id,
                'product_id' => $row['product']->id,
                'sort_order' => $i,
            ]);

            // Bind the topping product's first ProductSku so the POS picker can
            // resolve a non-null product_sku_id (#1708) — a NULL row is silently
            // unselectable. Mirrors ProductToppingSeeder::attachItems.
            $skuId = ProductSku::where('product_id', $row['product']->id)
                ->orderBy('created_at')
                ->value('id');

            ToppingGroupItemSku::create([
                'topping_group_item_id' => $item->id,
                'product_sku_id' => $skuId,
                'extra_price' => $row['extra_price'],
            ]);
        }
    }

    /**
     * Attach a topping group to a product with default (no override) settings.
     */
    private function attach(Product $product, ToppingGroup $group): void
    {
        ProductToppingGroup::create([
            'product_id' => $product->id,
            'topping_group_id' => $group->id,
            'sort_order' => 0,
        ]);
    }

    /**
     * Upsert translations for a topping group so all locales are populated.
     *
     * @param  array<string, string>  $names  locale => name
     */
    private function syncTranslations(ToppingGroup $group, array $names): void
    {
        foreach ($names as $locale => $name) {
            DB::table('topping_group_translations')->updateOrInsert(
                ['topping_group_id' => $group->id, 'locale' => $locale],
                ['name' => $name],
            );
        }
    }
}
