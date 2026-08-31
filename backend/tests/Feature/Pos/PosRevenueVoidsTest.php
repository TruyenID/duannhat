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
 * Feature coverage for GET /api/v1/pos/revenue/voids — cancellation analytics.
 * Two NON-overlapping lenses:
 *   - order voids : wholly-voided orders; value = their items' subtotals.
 *   - item voids  : per-item voids where the parent order is NOT voided (so an
 *     item inside a voided order is never double-counted).
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'voids-shop',
        'is_active' => true,
    ]);
    $managerRole = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);
});

it('splits voids into order + item lenses with reasons and top items', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    $mkItem = function (string $orderId, string $status, int $subtotal, ?string $reason, ?string $voidedAt) use ($sku) {
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $orderId,
            'product_sku_id' => $sku->id,
            'quantity' => 1,
            'unit_price' => $subtotal,
            'subtotal' => $subtotal,
            'status' => $status,
            'void_reason' => $reason,
            'voided_at' => $voidedAt,
        ]);
    };

    // Wholly-voided order (manager_void). total_amount zeroed; items 2000 + 1000
    // = 3000 lost value. Its items must NOT count as per-item voids.
    $vo = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->shop->id,
        'status' => 'voided', 'total_amount' => 0, 'guest_count' => 2,
        'void_reason' => 'manager_void', 'voided_at' => '2026-05-01 12:00:00', 'created_at' => '2026-05-01 11:00:00',
    ]);
    $mkItem($vo->id, 'voided', 2000, 'manager_void', '2026-05-01 12:00:00');
    $mkItem($vo->id, 'served', 1000, null, null);

    // Closed order with ONE per-item void (wrong_item, value 1200).
    $co = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->shop->id,
        'status' => 'closed', 'total_amount' => 5000, 'guest_count' => 2,
        'created_at' => '2026-05-01 13:00:00', 'closed_at' => '2026-05-01 15:00:00',
    ]);
    $mkItem($co->id, 'voided', 1200, 'wrong_item', '2026-05-01 14:00:00');

    $data = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/voids?granularity=day&from=2026-05-01&to=2026-05-01')
        ->assertOk()
        ->json('data');

    expect($data['kpis']['order_voids'])->toBe(1);
    expect($data['kpis']['order_void_value'])->toBe(3000); // both items of the voided order
    expect($data['kpis']['item_voids'])->toBe(1);           // only the per-item void
    expect($data['kpis']['item_void_value'])->toBe(1200);
    expect((float) $data['kpis']['order_void_rate_pct'])->toBe(50.0); // 1 voided / (1 voided + 1 closed)

    expect(collect($data['order_reasons'])->pluck('reason'))->toContain('manager_void');
    expect(collect($data['item_reasons'])->pluck('reason'))->toContain('wrong_item');

    // Top voided items: one per-item void → count 1, value 1200.
    expect($data['top_items'])->toHaveCount(1);
    expect($data['top_items'][0]['count'])->toBe(1);
    expect($data['top_items'][0]['value'])->toBe(1200);

    // Series backfills the single-day window and carries the counts.
    expect(collect($data['series'])->sum('order_voids'))->toBe(1);
    expect(collect($data['series'])->sum('item_voids'))->toBe(1);
});

it('lists individual void events (which order, when, reason) newest-first', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id]);

    $mkItem = function (string $orderId, string $status, int $subtotal, ?string $reason, ?string $voidedAt) use ($sku) {
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $orderId,
            'product_sku_id' => $sku->id,
            'quantity' => 1,
            'unit_price' => $subtotal,
            'subtotal' => $subtotal,
            'status' => $status,
            'void_reason' => $reason,
            'voided_at' => $voidedAt,
        ]);
    };

    // Wholly-voided order (earlier). 2 items = 3000 lost value.
    $vo = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->shop->id,
        'status' => 'voided', 'total_amount' => 0, 'order_code' => 'ORD-2026-0001',
        'void_reason' => 'manager_void', 'voided_at' => '2026-05-01 10:00:00', 'created_at' => '2026-05-01 09:00:00',
    ]);
    $mkItem($vo->id, 'voided', 2000, 'manager_void', '2026-05-01 10:00:00');
    $mkItem($vo->id, 'served', 1000, null, null);

    // Closed order with a per-item void (later → should sort first).
    $co = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->shop->id,
        'status' => 'closed', 'total_amount' => 5000, 'order_code' => 'ORD-2026-0002',
        'created_at' => '2026-05-01 13:00:00', 'closed_at' => '2026-05-01 15:00:00',
    ]);
    $mkItem($co->id, 'voided', 1200, 'wrong_item', '2026-05-01 14:00:00');

    $data = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/void-events?granularity=day&from=2026-05-01&to=2026-05-01')
        ->assertOk()
        ->json('data');

    expect($data['total'])->toBe(2);
    expect($data['rows'])->toHaveCount(2);

    // Newest-first: the item void at 14:00 precedes the order void at 10:00.
    expect($data['rows'][0]['kind'])->toBe('item');
    expect($data['rows'][0]['order_code'])->toBe('ORD-2026-0002');
    expect($data['rows'][0]['reason'])->toBe('wrong_item');
    expect($data['rows'][0]['value'])->toBe(1200);
    expect($data['rows'][0]['quantity'])->toBe(1);

    expect($data['rows'][1]['kind'])->toBe('order');
    expect($data['rows'][1]['order_code'])->toBe('ORD-2026-0001');
    expect($data['rows'][1]['reason'])->toBe('manager_void');
    expect($data['rows'][1]['value'])->toBe(3000);     // both items of the voided order
    expect($data['rows'][1]['item_count'])->toBe(2);

    // type=order narrows to the whole-order void only.
    $orders = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/void-events?granularity=day&from=2026-05-01&to=2026-05-01&type=order')
        ->assertOk()
        ->json('data');
    expect($orders['total'])->toBe(1);
    expect($orders['rows'][0]['kind'])->toBe('order');

    // Pagination: per_page=1 returns page 1 of 2.
    $paged = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/void-events?granularity=day&from=2026-05-01&to=2026-05-01&per_page=1&page=2')
        ->assertOk()
        ->json('data');
    expect($paged['total'])->toBe(2);
    expect($paged['rows'])->toHaveCount(1);
    expect($paged['rows'][0]['kind'])->toBe('order'); // page 2 = the older order void
});

it('localizes void top-item + event names by Accept-Language', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Fresh Spring Rolls',
    ]);
    $sku = ProductSku::factory()->create(['product_id' => $product->id, 'name' => '2pc']);

    // ja translations (Astrotomic tables the report joins).
    DB::table('product_translations')->insert([
        'product_id' => $product->id, 'locale' => 'ja', 'name' => '生春巻き',
    ]);
    DB::table('product_sku_translations')->insert([
        'product_sku_id' => $sku->id, 'locale' => 'ja', 'name' => '2個',
    ]);

    // Closed order with a per-item void.
    $co = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->shop->id,
        'status' => 'closed', 'total_amount' => 600, 'order_code' => 'ORD-2026-0001',
        'created_at' => '2026-05-01 13:00:00', 'closed_at' => '2026-05-01 15:00:00',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $co->id, 'product_sku_id' => $sku->id,
        'quantity' => 1, 'unit_price' => 600, 'subtotal' => 600,
        'status' => 'voided', 'void_reason' => 'wrong_item', 'voided_at' => '2026-05-01 14:00:00',
    ]);

    // voids: top_items follow ja.
    $data = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Accept-Language', 'ja')
        ->getJson('/api/v1/pos/revenue/voids?granularity=day&from=2026-05-01&to=2026-05-01')
        ->assertOk()->json('data');
    expect($data['top_items'][0]['name'])->toBe('生春巻き');
    expect($data['top_items'][0]['variant'])->toBe('2個');

    // void-events: item_name + variant follow ja.
    $ev = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Accept-Language', 'ja')
        ->getJson('/api/v1/pos/revenue/void-events?granularity=day&from=2026-05-01&to=2026-05-01&type=item')
        ->assertOk()->json('data');
    expect($ev['rows'][0]['item_name'])->toBe('生春巻き');
    expect($ev['rows'][0]['variant'])->toBe('2個');

    // A different locale with no translation falls back to the base name.
    $en = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->withHeader('Accept-Language', 'en')
        ->getJson('/api/v1/pos/revenue/voids?granularity=day&from=2026-05-01&to=2026-05-01')
        ->assertOk()->json('data');
    expect($en['top_items'][0]['name'])->toBe('Fresh Spring Rolls');
});

it('excludes voids from other branches', function () {
    $other = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'other-voids-shop',
    ]);
    CustomerOrder::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $other->id,
        'status' => 'voided', 'total_amount' => 0, 'void_reason' => 'x', 'voided_at' => '2026-05-01 12:00:00',
        'created_at' => '2026-05-01 11:00:00',
    ]);

    $data = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/voids?granularity=day&from=2026-05-01&to=2026-05-01')
        ->assertOk()
        ->json('data');

    expect($data['kpis']['order_voids'])->toBe(0);
});
