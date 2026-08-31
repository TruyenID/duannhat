<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Feature coverage for GET /api/v1/pos/revenue/by-product — the
 *商品別売上 / "Theo sản phẩm" tab on the pos-web revenue screen.
 *
 * Proves the aggregation honours branch + status + window + category,
 * computes share_pct correctly, and serves the available-categories
 * filter list scoped to the shop's brand.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'by-product-shop',
        'is_active' => true,
    ]);

    $managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);
});

function makeByProductOrder(string $branchId, string $brandId, string $organizationId, string $createdAt): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $organizationId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'status' => 'closed',
        'total_amount' => 0, // populated via items in fact
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'closed_at' => $createdAt,
    ]);
}

function makeByProductItem(string $orderId, string $skuId, int $quantity, int $unitPrice): void
{
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $orderId,
        'product_sku_id' => $skuId,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'subtotal' => $quantity * $unitPrice,
    ]);
}

it('ranks products by total revenue with share %', function () {
    // 3 products, each with multiple sales — share% should reflect ratio.
    $beef = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Beef Pho']);
    $chicken = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Chicken Pho']);
    $banh = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Banh Mi']);

    $beefSku = ProductSku::factory()->create(['product_id' => $beef->id]);
    $chickenSku = ProductSku::factory()->create(['product_id' => $chicken->id]);
    $banhSku = ProductSku::factory()->create(['product_id' => $banh->id]);

    $order = makeByProductOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-01 12:00:00');
    makeByProductItem($order->id, $beefSku->id, 10, 60_000);    // 600k
    makeByProductItem($order->id, $chickenSku->id, 5, 50_000);  //  250k
    makeByProductItem($order->id, $banhSku->id, 3, 50_000);     //  150k

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01')
        ->assertOk()
        ->json('data');

    expect($response['total_revenue'])->toBe(1_000_000);
    expect($response['total_quantity'])->toBe(18);
    expect($response['rows'][0]['name'])->toBe('Beef Pho');
    expect($response['rows'][0]['revenue'])->toBe(600_000);
    expect($response['rows'][0]['share_pct'])->toEqual(60);
    expect($response['rows'][1]['name'])->toBe('Chicken Pho');
    expect($response['rows'][1]['share_pct'])->toEqual(25);
    expect($response['rows'][2]['name'])->toBe('Banh Mi');
    expect($response['rows'][2]['share_pct'])->toEqual(15);
});

it('excludes items from voided or other-branch orders', function () {
    $beef = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Beef Pho']);
    $beefSku = ProductSku::factory()->create(['product_id' => $beef->id]);

    $closed = makeByProductOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-01 12:00:00');
    makeByProductItem($closed->id, $beefSku->id, 2, 50_000);

    $voided = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'voided',
        'created_at' => '2026-05-01 12:00:00',
    ]);
    makeByProductItem($voided->id, $beefSku->id, 99, 999_999);

    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $other = makeByProductOrder($otherBranch->id, $this->brand->id, $this->orgId, '2026-05-01 12:00:00');
    makeByProductItem($other->id, $beefSku->id, 50, 50_000);

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01')
        ->assertOk()
        ->json('data');

    expect($response['total_revenue'])->toBe(100_000);
    expect($response['rows'])->toHaveCount(1);
    expect($response['rows'][0]['revenue'])->toBe(100_000);
});

it('returns SKU-level rows when level=sku', function () {
    $beef = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Beef Pho']);
    // Attach distinct option values so the observer-computed
    // option_signature differs between the two SKUs (otherwise the
    // unique (product_id, signature) constraint rejects the second).
    $sku1 = ProductSku::factory()
        ->withSequencedOption()
        ->create(['product_id' => $beef->id, 'name' => 'Small']);
    $sku2 = ProductSku::factory()
        ->withSequencedOption()
        ->create(['product_id' => $beef->id, 'name' => 'Large']);

    $order = makeByProductOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-01 12:00:00');
    makeByProductItem($order->id, $sku1->id, 3, 50_000);  // 150k
    makeByProductItem($order->id, $sku2->id, 2, 80_000);  // 160k

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01&level=sku')
        ->assertOk()
        ->json('data');

    expect($response['level'])->toBe('sku');
    expect($response['rows'])->toHaveCount(2);
    // Sorted by revenue desc → Large variant first
    expect($response['rows'][0]['name'])->toContain('Large');
    expect($response['rows'][1]['name'])->toContain('Small');
});

it('localizes the product + SKU variant name by Accept-Language', function () {
    $coffee = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Vietnamese Coffee']);
    $sku = ProductSku::factory()->withSequencedOption()->create(['product_id' => $coffee->id, 'name' => 'Iced']);

    DB::table('product_translations')->insert([
        'product_id' => $coffee->id, 'locale' => 'ja', 'name' => 'ベトナムコーヒー',
    ]);
    DB::table('product_sku_translations')->insert([
        'product_sku_id' => $sku->id, 'locale' => 'ja', 'name' => 'アイス',
    ]);

    $order = makeByProductOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-01 12:00:00');
    makeByProductItem($order->id, $sku->id, 1, 45_000);

    $ja = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Accept-Language', 'ja')
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01&level=sku')
        ->assertOk()->json('data');
    // name = CONCAT_WS(' / ', <product ja>, <variant ja>)
    expect($ja['rows'][0]['name'])->toContain('ベトナムコーヒー');
    expect($ja['rows'][0]['name'])->toContain('アイス');

    // en (no translation) → base names.
    $en = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Accept-Language', 'en')
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01&level=sku')
        ->assertOk()->json('data');
    expect($en['rows'][0]['name'])->toContain('Vietnamese Coffee');
    expect($en['rows'][0]['name'])->toContain('Iced');
});

it('filters by menu-section id and surfaces sections from this shop\'s menus', function () {
    $beef = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Beef Pho']);
    $banh = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Banh Mi']);

    // A shop-scoped menu with one section "Pho", containing only Beef Pho.
    $menuId = (string) Str::uuid();
    DB::table('menus')->insert([
        'id' => $menuId,
        'name' => 'Lunch Menu',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'organization_id' => $this->orgId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sectionPho = (string) Str::uuid();
    DB::table('menu_sections')->insert([
        'id' => $sectionPho,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Pho',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('menu_menu_sections')->insert([
        'menu_id' => $menuId,
        'menu_section_id' => $sectionPho,
        'display_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('menu_products')->insert([
        'id' => (string) Str::uuid(),
        'menu_id' => $menuId,
        'product_id' => $beef->id,
        'menu_section_id' => $sectionPho,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $beefSku = ProductSku::factory()->create(['product_id' => $beef->id]);
    $banhSku = ProductSku::factory()->create(['product_id' => $banh->id]);

    $order = makeByProductOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-01 12:00:00');
    makeByProductItem($order->id, $beefSku->id, 5, 60_000);
    makeByProductItem($order->id, $banhSku->id, 5, 50_000);

    // Unfiltered: both products present + Pho section in available list
    $all = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01')
        ->assertOk()
        ->json('data');
    expect($all['rows'])->toHaveCount(2);
    expect(collect($all['available_categories'])->pluck('id'))->toContain($sectionPho);

    // Filtered by section: only the product attached to that section.
    $filtered = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson("/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01&category_id={$sectionPho}")
        ->assertOk()
        ->json('data');
    expect($filtered['rows'])->toHaveCount(1);
    expect($filtered['rows'][0]['name'])->toBe('Beef Pho');
    expect($filtered['category_id'])->toBe($sectionPho);
});

it('paginates rows + keeps totals + share_pct across the full filtered set', function () {
    // Create 12 distinct products with descending revenue so the sort
    // order is deterministic.
    for ($i = 0; $i < 12; $i++) {
        $p = Product::factory()->create([
            'brand_id' => $this->brand->id,
            'name' => "Item {$i}",
        ]);
        $sku = ProductSku::factory()->create(['product_id' => $p->id]);
        $order = makeByProductOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-01 12:00:00');
        // Revenue 10000, 9000, 8000, …, with quantity 10 each.
        makeByProductItem($order->id, $sku->id, 10, 10_000 - $i * 1_000);
    }

    // Page 1: per_page=5 → first 5 highest-revenue rows.
    $page1 = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01&per_page=5&page=1')
        ->assertOk()
        ->json('data');

    expect($page1['rows'])->toHaveCount(5);
    expect($page1['meta'])->toEqual([
        'current_page' => 1,
        'last_page' => 3,
        'per_page' => 5,
        'total' => 12,
    ]);
    expect($page1['rows'][0]['name'])->toBe('Item 0');
    expect($page1['rows'][0]['revenue'])->toBe(100_000); // 10 × 10_000
    // total_revenue + total_quantity span ALL 12 rows, not just page 1
    expect($page1['total_revenue'])->toBe(540_000); // sum of 100..10k descending arithmetic
    expect($page1['total_quantity'])->toBe(120);
    // share_pct uses the full-filter total, NOT the page's subset.
    expect($page1['rows'][0]['share_pct'])->toEqual(round(100_000 * 100 / 540_000, 1));

    // Page 2: next 5 rows
    $page2 = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01&per_page=5&page=2')
        ->assertOk()
        ->json('data');
    expect($page2['rows'])->toHaveCount(5);
    expect($page2['meta']['current_page'])->toBe(2);
    expect($page2['rows'][0]['name'])->toBe('Item 5');

    // Page 3: only 2 rows (last partial page)
    $page3 = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01&per_page=5&page=3')
        ->assertOk()
        ->json('data');
    expect($page3['rows'])->toHaveCount(2);
    expect($page3['meta']['current_page'])->toBe(3);

    // Overflow page clamps to last_page on the server (consistent with
    // FE behaviour) — still returns data, not 404.
    $overflow = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01&per_page=5&page=99')
        ->assertOk()
        ->json('data');
    expect($overflow['meta']['current_page'])->toBe(3);
    expect($overflow['rows'])->toHaveCount(2);
});

it('rejects per_page > 100 with 422', function () {
    $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?per_page=500')
        ->assertStatus(422);
});

it('rejects an inverted window with 422', function () {
    $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-31&to=2026-05-01')
        ->assertStatus(422);
});

it('exposes 税抜 net / 税込 gross / per-rate tax alongside the net total_revenue', function () {
    // plan-043 T4.6 — bentō ¥1,000 @8% + beer ¥500 @10%. total_revenue (net /
    // 税抜) = 1,500 (Σ subtotal); gross (税込) = 1,630; tax = 130.
    $bento = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Bento']);
    $beer = Product::factory()->create(['brand_id' => $this->brand->id, 'name' => 'Beer']);
    $bentoSku = ProductSku::factory()->create(['product_id' => $bento->id]);
    $beerSku = ProductSku::factory()->create(['product_id' => $beer->id]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'closed',
        'total_amount' => 1_630,
        'created_at' => '2026-05-01 12:00:00',
        'closed_at' => '2026-05-01 12:00:00',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $bentoSku->id,
        'quantity' => 1,
        'unit_price' => 1_000,
        'subtotal' => 1_000,
        'tax_rate' => 8,
        'tax_amount' => 80,
        'status' => 'served',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $beerSku->id,
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
        'tax_rate' => 10,
        'tax_amount' => 50,
        'status' => 'served',
    ]);

    $data = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/by-product?from=2026-05-01&to=2026-05-01')
        ->assertOk()
        ->json('data');

    // total_revenue stays the net (税抜) Σ subtotal convention.
    expect($data['total_revenue'])->toBe(1_500);
    expect($data['net'])->toBe(1_500);
    expect($data['tax'])->toBe(130);
    expect($data['gross'])->toBe(1_630);
    // net + tax = gross reconciles against the summary tab's gross figure.
    expect($data['net'] + $data['tax'])->toBe($data['gross']);

    $byRate = collect($data['by_tax_rate'])->keyBy(fn ($r) => (int) $r['rate']);
    expect($byRate->get(8)['tax'])->toBe(80);
    expect($byRate->get(10)['tax'])->toBe(50);
});
