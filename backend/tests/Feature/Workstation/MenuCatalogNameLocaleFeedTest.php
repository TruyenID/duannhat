<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Menu;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The workstation menu-catalog feed must surface each product's per-locale
 * names (name_ja / name_en / name_vi) so the LAN workstation can store all
 * three and serve the item name in the pos-web operator's language offline —
 * not just the single resolved `name`.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('emits name_ja / name_en / name_vi from product_translations', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'フォー・ボー',
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    // The Translatable factory auto-creates a translation for the base name in
    // the app locale; clear it so our explicit ja/en/vi set is authoritative
    // (and doesn't hit the unique (product_id, locale) constraint).
    DB::table('product_translations')->where('product_id', $product->id)->delete();
    foreach ([['ja', 'フォー・ボー'], ['en', 'Beef Pho'], ['vi', 'Phở Bò']] as [$loc, $name]) {
        DB::table('product_translations')->insert([
            'product_id' => $product->id,
            'locale' => $loc,
            'name' => $name,
        ]);
    }

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
    $mpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $mpId,
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    DB::table('menu_product_skus')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $mpId,
        'product_sku_id' => $sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $payload = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-catalog')
        ->assertOk()
        ->json('data');

    $productOut = collect($payload['products'])->firstWhere('id', $product->id);

    expect($productOut)->not->toBeNull()
        ->and($productOut['name_ja'])->toBe('フォー・ボー')
        ->and($productOut['name_en'])->toBe('Beef Pho')
        ->and($productOut['name_vi'])->toBe('Phở Bò');
});

it('emits null per-locale names when a product has no translations', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Plain Item',
    ]);
    // No translations at all — clear the factory's auto-created one so the base
    // `name` is the only source (name_ja/en/vi must be null).
    DB::table('product_translations')->where('product_id', $product->id)->delete();
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'selling_price' => 500,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
    $mpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $mpId,
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    DB::table('menu_product_skus')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $mpId,
        'product_sku_id' => $sku->id,
        'selling_price' => 500,
        'is_active' => true,
    ]);

    $productOut = collect(
        $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
            ->getJson('/api/v1/workstation/menu-catalog')
            ->assertOk()
            ->json('data.products')
    )->firstWhere('id', $product->id);

    expect($productOut['name'])->toBe('Plain Item')
        ->and($productOut['name_ja'])->toBeNull()
        ->and($productOut['name_en'])->toBeNull()
        ->and($productOut['name_vi'])->toBeNull();
});

it('emits localized names for skus, topping groups, options and option values', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Base',
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
        'selling_price' => 1000,
        'name' => 'Regular',
    ]);
    seedWorkstationTranslations('product_sku_translations', 'product_sku_id', $sku->id, 'name', ['ja' => 'レギュラー', 'en' => 'Regular', 'vi' => 'Thường']);

    // Topping group attached to the product.
    $group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
        'name' => 'Sauce',
    ]);
    DB::table('product_topping_groups')->insert([
        'product_id' => $product->id,
        'topping_group_id' => $group->id,
        'sort_order' => 0,
    ]);
    seedWorkstationTranslations('topping_group_translations', 'topping_group_id', $group->id, 'name', ['ja' => 'ソース', 'en' => 'Sauce', 'vi' => 'Sốt']);

    // Product option + option value.
    $option = ProductOption::factory()->create([
        'product_id' => $product->id,
        'name' => 'Size',
        'is_active' => true,
    ]);
    seedWorkstationTranslations('product_option_translations', 'product_option_id', $option->id, 'name', ['ja' => 'サイズ', 'en' => 'Size', 'vi' => 'Cỡ']);

    $value = ProductOptionValue::factory()->create([
        'option_id' => $option->id,
        'value' => 'regular',
        'is_active' => true,
    ]);
    seedWorkstationTranslations('product_option_value_translations', 'product_option_value_id', $value->id, 'label', ['ja' => '並', 'en' => 'Regular', 'vi' => 'Thường']);

    $menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
    $mpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $mpId,
        'menu_id' => $menu->id,
        'product_id' => $product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    DB::table('menu_product_skus')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $mpId,
        'product_sku_id' => $sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $data = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu-catalog')
        ->assertOk()
        ->json('data');

    $skuOut = collect($data['skus'])->firstWhere('id', $sku->id);
    expect($skuOut)->not->toBeNull()
        ->and($skuOut['name_ja'])->toBe('レギュラー')
        ->and($skuOut['name_vi'])->toBe('Thường');

    $groupOut = collect($data['topping_groups'])->firstWhere('id', $group->id);
    expect($groupOut)->not->toBeNull()
        ->and($groupOut['name_ja'])->toBe('ソース')
        ->and($groupOut['name_vi'])->toBe('Sốt');

    $optOut = collect($data['product_options'])->firstWhere('id', $option->id);
    expect($optOut)->not->toBeNull()
        ->and($optOut['name_ja'])->toBe('サイズ');

    $valOut = collect($data['product_option_values'])->firstWhere('id', $value->id);
    expect($valOut)->not->toBeNull()
        ->and($valOut['label_ja'])->toBe('並')
        ->and($valOut['label_vi'])->toBe('Thường');
});

/**
 * Replace a model's Astrotomic auto-created translation with an explicit ja/en/vi
 * set (the factories auto-create one in the app locale, which both duplicates and
 * trips the unique (fk, locale) constraint).
 */
function seedWorkstationTranslations(string $table, string $fk, string $id, string $col, array $byLocale): void
{
    DB::table($table)->where($fk, $id)->delete();
    foreach ($byLocale as $locale => $value) {
        DB::table($table)->insert([$fk => $id, 'locale' => $locale, $col => $value]);
    }
}
