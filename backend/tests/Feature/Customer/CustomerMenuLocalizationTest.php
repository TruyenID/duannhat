<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductOption;
use App\Models\ProductOptionValue;
use App\Models\ProductSku;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'slug' => 'betoya-localization-test',
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'jimbocho-localization-test',
        'name' => '神保町店',
    ]);

    seedTranslations('branch_translations', 'branch_id', $this->branch->id, 'name', [
        'ja' => '神保町店',
        'en' => 'Jimbocho Store',
        'vi' => 'Cửa hàng Jimbocho',
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'name' => '神保町店 メニュー',
        'description' => null,
        'status' => 'Active',
        'service_type' => 'Both',
        'priority' => 1,
        'valid_from' => null,
        'valid_to' => null,
    ]);
    seedTranslations('menu_translations', 'menu_id', $this->menu->id, 'name', [
        'ja' => '神保町店 メニュー',
        'en' => 'Jimbocho Store Menu',
        'vi' => 'Menu cửa hàng Jimbocho',
    ]);

    $this->section = MenuSection::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'name' => 'フォー',
    ]);
    seedTranslations('menu_section_translations', 'menu_section_id', $this->section->id, 'name', [
        'ja' => 'フォー',
        'en' => 'Pho',
        'vi' => 'Phở',
    ]);
    $this->menu->menuSections()->attach($this->section, ['display_order' => 0]);

    $this->product = Product::factory()->active()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'name' => '定番牛肉フォー',
        'description' => '定番の説明',
    ]);
    seedProductTranslations($this->product->id, [
        'ja' => ['name' => '定番牛肉フォー', 'description' => '定番の説明'],
        'en' => ['name' => 'Classic Beef Pho', 'description' => 'Tender beef in aromatic broth.'],
        'vi' => ['name' => 'Phở bò tái', 'description' => 'Thịt bò mềm trong nước dùng thơm.'],
    ]);

    $this->option = ProductOption::factory()->create([
        'product_id' => $this->product->id,
        'key' => 'size',
        'name' => 'サイズ',
        'position' => 0,
    ]);
    seedTranslations('product_option_translations', 'product_option_id', $this->option->id, 'name', [
        'ja' => 'サイズ',
        'en' => 'Size',
        'vi' => 'Kích cỡ',
    ]);

    $this->optionValue = ProductOptionValue::factory()->create([
        'option_id' => $this->option->id,
        'value' => 'regular',
        'label' => '並盛',
        'position' => 0,
    ]);
    seedTranslations('product_option_value_translations', 'product_option_value_id', $this->optionValue->id, 'label', [
        'ja' => '並盛',
        'en' => 'Regular',
        'vi' => 'Thường',
    ]);

    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'option_value1_id' => $this->optionValue->id,
        'name' => '並盛',
        'selling_price' => 1000,
    ]);
    seedTranslations('product_sku_translations', 'product_sku_id', $this->sku->id, 'name', [
        'ja' => '並盛',
        'en' => 'Regular',
        'vi' => 'Thường',
    ]);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $this->menu->id,
        'product_id' => $this->product->id,
        'menu_section_id' => $this->section->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $this->toppingProduct = Product::factory()->active()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'name' => 'パクチー追加',
    ]);
    seedProductTranslations($this->toppingProduct->id, [
        'ja' => ['name' => 'パクチー追加', 'description' => null],
        'en' => ['name' => 'Extra Coriander', 'description' => null],
        'vi' => ['name' => 'Thêm rau mùi', 'description' => null],
    ]);
    $toppingSku = ProductSku::factory()->create(['product_id' => $this->toppingProduct->id]);

    $this->toppingGroup = ToppingGroup::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'name' => 'フォーのトッピング',
        'min_select' => 0,
        'max_select' => 1,
    ]);
    seedTranslations('topping_group_translations', 'topping_group_id', $this->toppingGroup->id, 'name', [
        'ja' => 'フォーのトッピング',
        'en' => 'Pho Toppings',
        'vi' => 'Topping phở',
    ]);
    $toppingItem = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->toppingGroup->id,
        'product_id' => $this->toppingProduct->id,
    ]);
    ToppingGroupItemSku::factory()->noVariant()->create([
        'topping_group_item_id' => $toppingItem->id,
        'product_sku_id' => $toppingSku->id,
        'extra_price' => 100,
    ]);
    $this->product->toppingGroups()->attach($this->toppingGroup, ['sort_order' => 0]);
});

it('localizes the complete customer menu tree from Accept-Language', function (string $locale, array $expected) {
    $response = $this->withHeader('Accept-Language', $locale)
        ->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")
        ->assertOk();

    $data = $response->json('data');
    $item = $data['categories'][0]['items'][0];

    expect($data['menu_name'])->toBe($expected['menu'])
        ->and($data['categories'][0]['name'])->toBe($expected['section'])
        ->and($item['name'])->toBe($expected['product'])
        ->and($item['description'])->toBe($expected['description'])
        ->and($item['options'][0]['name'])->toBe($expected['option'])
        ->and($item['options'][0]['variants'][0]['name'])->toBe($expected['variant'])
        ->and($item['toppingGroups'][0]['name'])->toBe($expected['topping_group'])
        ->and($item['toppingGroups'][0]['items'][0]['name'])->toBe($expected['topping_item'])
        ->and(json_encode($data, JSON_UNESCAPED_UNICODE))->not->toContain('Unknown');
})->with([
    'Japanese' => ['ja', [
        'menu' => '神保町店 メニュー',
        'section' => 'フォー',
        'product' => '定番牛肉フォー',
        'description' => '定番の説明',
        'option' => 'サイズ',
        'variant' => '並盛',
        'topping_group' => 'フォーのトッピング',
        'topping_item' => 'パクチー追加',
    ]],
    'English' => ['en', [
        'menu' => 'Jimbocho Store Menu',
        'section' => 'Pho',
        'product' => 'Classic Beef Pho',
        'description' => 'Tender beef in aromatic broth.',
        'option' => 'Size',
        'variant' => 'Regular',
        'topping_group' => 'Pho Toppings',
        'topping_item' => 'Extra Coriander',
    ]],
    'Vietnamese' => ['vi', [
        'menu' => 'Menu cửa hàng Jimbocho',
        'section' => 'Phở',
        'product' => 'Phở bò tái',
        'description' => 'Thịt bò mềm trong nước dùng thơm.',
        'option' => 'Kích cỡ',
        'variant' => 'Thường',
        'topping_group' => 'Topping phở',
        'topping_item' => 'Thêm rau mùi',
    ]],
]);

it('localizes branch names in the public branch picker API', function (string $locale, string $name) {
    $rows = $this->withHeader('Accept-Language', $locale)
        ->getJson('/api/v1/customer/branches')
        ->assertOk()
        ->json('data');

    expect(collect($rows)->firstWhere('id', $this->branch->id)['name'])->toBe($name);
})->with([
    ['ja', '神保町店'],
    ['en', 'Jimbocho Store'],
    ['vi', 'Cửa hàng Jimbocho'],
]);

it('does not report a fallback for an intentionally unnamed technical sku', function () {
    DB::table('product_sku_translations')->where('product_sku_id', $this->sku->id)->delete();
    DB::table('product_skus')->where('id', $this->sku->id)->update(['name' => null]);

    $this->withHeader('Accept-Language', 'en')
        ->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")
        ->assertOk()
        ->assertHeaderMissing('X-Localization-Fallback-Count')
        ->assertJsonPath('data.categories.0.items.0.options.0.variants.0.name', 'Regular');
});

it('does not render dangling topping items whose product was deleted', function () {
    Cache::flush();
    Log::spy();
    $deletedProduct = Product::factory()->active()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'name' => 'Retired Pho',
    ]);
    $deletedItem = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->toppingGroup->id,
        'product_id' => $deletedProduct->id,
        'sort_order' => 99,
    ]);
    ToppingGroupItemSku::factory()->noVariant()->create([
        'topping_group_item_id' => $deletedItem->id,
        'extra_price' => 0,
    ]);
    $deletedProduct->delete();

    $payload = $this->withHeader('Accept-Language', 'en')
        ->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")
        ->assertOk()
        ->json('data');

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    expect($encoded)->not->toContain('Unknown')
        ->and($encoded)->not->toContain('Retired Pho');
    Log::shouldHaveReceived('warning')->once()->with(
        'menu.localization.integrity',
        Mockery::on(fn (array $context) => $context['reason_code'] === 'invalid_topping_relation'
            && $context['result'] === 'excluded'
            && $context['menu_id'] === (string) $this->menu->id
            && $context['affected_count'] === 1
            && is_string($context['request_id'])),
    );
});

it('resolves weighted and regional Accept-Language values and advertises the selected locale', function (string $header, string $expectedLocale, string $expectedMenu) {
    $this->withHeader('Accept-Language', $header)
        ->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")
        ->assertOk()
        ->assertHeader('Content-Language', $expectedLocale)
        ->assertHeader('Vary', 'Accept-Language, Cookie')
        ->assertJsonPath('data.menu_name', $expectedMenu);
})->with([
    'regional English' => ['en-US,en;q=0.9,ja;q=0.5', 'en', 'Jimbocho Store Menu'],
    'Vietnamese wins q-value' => ['ja;q=0.2,vi-VN;q=0.9,en;q=0.5', 'vi', 'Menu cửa hàng Jimbocho'],
    'mixed case Japanese' => ['JA-jp,en;q=0.1', 'ja', '神保町店 メニュー'],
]);

it('uses a valid query locale ahead of cookie and header and persists that explicit choice', function () {
    $this->withCookie('app_locale', 'ja')
        ->withHeader('Accept-Language', 'en')
        ->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu?locale=vi")
        ->assertOk()
        ->assertHeader('Content-Language', 'vi')
        ->assertPlainCookie('app_locale', 'vi')
        ->assertJsonPath('data.menu_name', 'Menu cửa hàng Jimbocho');
});

it('ignores unsupported and malformed locale hints and falls back deterministically', function (string $header) {
    $this->withHeader('Accept-Language', $header)
        ->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu?locale=fr")
        ->assertOk()
        ->assertHeader('Content-Language', 'en')
        ->assertJsonPath('data.menu_name', 'Jimbocho Store Menu');
})->with([
    'unsupported' => ['fr-FR,fr;q=0.9'],
    'wildcard' => ['*'],
    'malformed quality' => ['ja;q=bogus,en;q=0.8'],
]);

it('excludes inactive topping products just like soft-deleted ones', function () {
    $this->toppingProduct->update(['status' => 'inactive']);

    $payload = $this->withHeader('Accept-Language', 'en')
        ->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")
        ->assertOk()
        ->json('data');

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE);
    expect($encoded)->not->toContain('Extra Coriander')
        ->and($encoded)->not->toContain('Unknown');
});

it('falls back at every nested level and emits one deduplicated actionable signal', function () {
    Cache::flush();
    Log::spy();
    foreach ([
        ['menu_translations', 'menu_id', $this->menu->id],
        ['menu_section_translations', 'menu_section_id', $this->section->id],
        ['product_translations', 'product_id', $this->product->id],
        ['product_option_translations', 'product_option_id', $this->option->id],
        ['product_option_value_translations', 'product_option_value_id', $this->optionValue->id],
        ['product_sku_translations', 'product_sku_id', $this->sku->id],
        ['topping_group_translations', 'topping_group_id', $this->toppingGroup->id],
        ['product_translations', 'product_id', $this->toppingProduct->id],
    ] as [$table, $foreignKey, $id]) {
        DB::table($table)->where($foreignKey, $id)->where('locale', 'en')->delete();
    }

    $url = "/api/v1/customer/branches/{$this->branch->slug}/menu";
    $first = $this->withHeader('Accept-Language', 'en')->getJson($url)->assertOk();
    $first->assertHeader('X-Localization-Fallback-Count');
    $encoded = json_encode($first->json('data'), JSON_UNESCAPED_UNICODE);
    expect((int) $first->headers->get('X-Localization-Fallback-Count'))->toBeGreaterThanOrEqual(8)
        ->and($encoded)->toContain('神保町店 メニュー')
        ->toContain('フォー')
        ->toContain('定番牛肉フォー')
        ->toContain('サイズ')
        ->toContain('並盛')
        ->toContain('フォーのトッピング')
        ->toContain('パクチー追加')
        ->not->toContain('Unknown');

    $this->withHeader('Accept-Language', 'en')->getJson($url)->assertOk();
    Log::shouldHaveReceived('warning')->once()->with(
        'menu.localization.integrity',
        Mockery::on(fn (array $context) => $context['reason_code'] === 'missing_translation'
            && $context['result'] === 'fallback'
            && $context['affected_count'] >= 8
            && count($context['paths']) >= 8
            && is_string($context['request_id'])),
    );
});

/*
 * #2850 — cửa sổ khử trùng phải khớp NHỊP ĐỔI của thứ đang đo.
 *
 * Bài ngay trên ghim "hai request liền nhau chỉ log một lần", và nó xanh với
 * cửa sổ 5 phút — nhưng production pull menu mỗi 5 phút, nên cửa sổ 5 phút cho
 * ra 288 dòng/ngày cho MỘT menu đứng yên. Đo hai ngày 08-13/14: 725 dòng, rút
 * gọn về đúng 4 payload.
 *
 * Hai chiều, và chiều thứ hai là chiều mà "sửa" bằng cách bóp cửa sổ sẽ giết:
 * một path thiếu dịch MỚI phải kêu NGAY, không chờ hết cửa sổ.
 */
it('#2850 trạng thái đứng yên chỉ log MỘT lần, kể cả sau cửa sổ 5 phút cũ', function () {
    Cache::flush();
    Log::spy();

    DB::table('product_translations')->where('product_id', $this->product->id)->where('locale', 'en')->delete();

    $url = "/api/v1/customer/branches/{$this->branch->slug}/menu";

    $this->withHeader('Accept-Language', 'en')->getJson($url)->assertOk();

    // Vượt hẳn cửa sổ 300s cũ. Với cửa sổ cũ, đây là dòng log THỨ HAI cho một
    // tình trạng không hề đổi — và là dòng thứ 288 trước khi hết ngày.
    $this->travel(10)->minutes();
    $this->withHeader('Accept-Language', 'en')->getJson($url)->assertOk();

    $this->travel(50)->minutes();
    $this->withHeader('Accept-Language', 'en')->getJson($url)->assertOk();

    Log::shouldHaveReceived('warning')->once()->with(
        'menu.localization.integrity',
        Mockery::on(fn (array $context): bool => $context['reason_code'] === 'missing_translation'),
    );

    $this->travelBack();
});

it('#2850 một path thiếu dịch MỚI vẫn kêu ngay trong cửa sổ', function () {
    Cache::flush();
    Log::spy();

    $url = "/api/v1/customer/branches/{$this->branch->slug}/menu";

    DB::table('product_translations')->where('product_id', $this->product->id)->where('locale', 'en')->delete();
    $this->withHeader('Accept-Language', 'en')->getJson($url)->assertOk();

    // Tập path đổi ⇒ vân tay đổi ⇒ phải log lại NGAY, dù mới vài giây. Đây là
    // thứ phân biệt "khử trùng" với "bịt miệng": nới cửa sổ mà bỏ vân tay đi
    // thì tin tức thật bị nuốt tới 24 giờ.
    DB::table('product_option_translations')->where('product_option_id', $this->option->id)->where('locale', 'en')->delete();
    $this->withHeader('Accept-Language', 'en')->getJson($url)->assertOk();

    Log::shouldHaveReceived('warning')->twice()->with(
        'menu.localization.integrity',
        Mockery::on(fn (array $context): bool => $context['reason_code'] === 'missing_translation'),
    );
});

it('keeps duplicate display ordering deterministic within a bounded query and payload budget', function () {
    foreach (range(1, 12) as $index) {
        $product = Product::factory()->active()->create([
            'organization_id' => $this->brand->console_organization_id,
            'brand_id' => $this->brand->id,
            'name' => "同順商品 {$index}",
        ]);
        seedProductTranslations($product->id, [
            'ja' => ['name' => "同順商品 {$index}", 'description' => null],
            'en' => ['name' => "Stable item {$index}", 'description' => null],
            'vi' => ['name' => "Món ổn định {$index}", 'description' => null],
        ]);
        $sku = ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 1000 + $index]);
        seedTranslations('product_sku_translations', 'product_sku_id', $sku->id, 'name', [
            'ja' => "同順商品 {$index}", 'en' => "Stable item {$index}", 'vi' => "Món ổn định {$index}",
        ]);
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $this->menu->id,
            'product_id' => $product->id,
            'menu_section_id' => $this->section->id,
            'display_order' => $index % 3,
            'is_active' => true,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku->id,
            'selling_price' => 1000 + $index,
            'is_active' => true,
        ]);
    }

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $response = $this->withHeader('Accept-Language', 'en')
        ->getJson("/api/v1/customer/branches/{$this->branch->slug}/menu")
        ->assertOk();
    $items = $response->json('data.categories.0.items');

    expect($items)->toHaveCount(13)
        ->and(collect($items)->pluck('id')->unique())->toHaveCount(13)
        ->and($queries)->toBeLessThanOrEqual(55)
        ->and(strlen($response->getContent()))->toBeLessThan(512 * 1024);
});

it('returns stable unavailable and empty contracts for inactive expired and empty menus', function () {
    $url = "/api/v1/customer/branches/{$this->branch->slug}/menu";

    $this->menu->update(['status' => 'inactive']);
    $this->withHeader('Accept-Language', 'en')->getJson($url)
        ->assertNotFound()
        ->assertExactJson([
            'code' => 'menu_unavailable',
            'message' => 'No menu is currently available for online ordering.',
        ]);

    $this->menu->update([
        'status' => 'Active',
        'valid_from' => now()->subDays(2),
        'valid_to' => now()->subDay(),
    ]);
    $this->withHeader('Accept-Language', 'en')->getJson($url)
        ->assertNotFound()
        ->assertExactJson([
            'code' => 'menu_unavailable',
            'message' => 'No menu is currently available for online ordering.',
        ]);

    $this->menu->update(['valid_from' => null, 'valid_to' => null]);
    $this->menu->menuProducts()->delete();
    $this->withHeader('Accept-Language', 'en')->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.categories', []);
});

/** @param array<string, string> $values */
function seedTranslations(string $table, string $foreignKey, string $id, string $field, array $values): void
{
    foreach ($values as $locale => $value) {
        DB::table($table)->updateOrInsert(
            [$foreignKey => $id, 'locale' => $locale],
            [$field => $value],
        );
    }
}

/** @param array<string, array{name: string, description: ?string}> $translations */
function seedProductTranslations(string $productId, array $translations): void
{
    foreach ($translations as $locale => $values) {
        DB::table('product_translations')->updateOrInsert(
            ['product_id' => $productId, 'locale' => $locale],
            $values,
        );
    }
}
