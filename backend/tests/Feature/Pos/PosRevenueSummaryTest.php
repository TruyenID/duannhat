<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Feature coverage for GET /api/v1/pos/revenue/summary — the daily / monthly
 * revenue aggregates that power the pos-web reports screen.
 *
 * The handler is intentionally branch-scoped (ResolvePosShop reads the
 * X-Shop-Slug header) and only counts closed orders, so these tests prove
 * that:
 *   - closed orders contribute to KPIs + series buckets,
 *   - voided / open orders don't,
 *   - other branches are excluded,
 *   - month granularity rolls daily rows up into one bucket per month.
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
        'slug' => 'revenue-shop',
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

function makePosClosedOrder(string $branchId, string $brandId, string $organizationId, string $createdAt, int $total, int $guests): void
{
    CustomerOrder::factory()->create([
        'organization_id' => $organizationId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'status' => 'closed',
        'total_amount' => $total,
        'guest_count' => $guests,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
        'closed_at' => $createdAt,
    ]);
}

it('aggregates closed orders into daily KPIs + series', function () {
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-01 10:00:00', 10_000, 2);
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-01 19:00:00', 5_000, 1);
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-02 12:00:00', 8_000, 3);

    // Voided + open should NOT contribute.
    CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'voided',
        'total_amount' => 99_999,
        'guest_count' => 9,
        'created_at' => '2026-05-01 11:00:00',
    ]);
    CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'open',
        'total_amount' => 77_777,
        'guest_count' => 7,
        'created_at' => '2026-05-01 11:30:00',
    ]);

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/summary?granularity=day&from=2026-05-01&to=2026-05-02')
        ->assertOk()
        ->json('data');

    expect($response['granularity'])->toBe('day');
    expect($response['kpis']['revenue'])->toBe(23_000);
    expect($response['kpis']['orders'])->toBe(3);
    expect($response['kpis']['guests'])->toBe(6);
    expect($response['kpis']['avg_per_guest'])->toBe((int) round(23_000 / 6));

    $series = collect($response['series'])->keyBy('period');
    expect($series->get('2026-05-01')['revenue'])->toBe(15_000);
    expect($series->get('2026-05-01')['orders'])->toBe(2);
    expect($series->get('2026-05-02')['revenue'])->toBe(8_000);
});

it('excludes orders from other branches', function () {
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-01 10:00:00', 10_000, 1);

    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'other-shop',
        'is_active' => true,
    ]);
    makePosClosedOrder($otherBranch->id, $this->brand->id, $this->orgId, '2026-05-01 10:00:00', 999_999, 5);

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/summary?granularity=day&from=2026-05-01&to=2026-05-01')
        ->assertOk()
        ->json('data');

    expect($response['kpis']['revenue'])->toBe(10_000);
});

it('rolls daily rows up into yearly buckets when granularity=year', function () {
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2024-03-15 10:00:00', 4_000, 1);
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2024-12-28 10:00:00', 6_000, 2);
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2025-02-02 10:00:00', 7_000, 1);
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-01-05 10:00:00', 9_000, 3);

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/summary?granularity=year&from=2024-01-01&to=2026-12-31')
        ->assertOk()
        ->json('data');

    expect($response['granularity'])->toBe('year');
    $series = collect($response['series'])->keyBy('period');
    expect($series->keys()->all())->toEqual(['2024', '2025', '2026']);
    expect($series->get('2024')['revenue'])->toBe(10_000);
    expect($series->get('2024')['orders'])->toBe(2);
    expect($series->get('2025')['revenue'])->toBe(7_000);
    expect($series->get('2026')['revenue'])->toBe(9_000);
});

it('rolls daily rows up into monthly buckets when granularity=month', function () {
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-03-15 10:00:00', 4_000, 1);
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-03-28 10:00:00', 6_000, 2);
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-04-02 10:00:00', 7_000, 1);

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/summary?granularity=month&from=2026-03-01&to=2026-04-30')
        ->assertOk()
        ->json('data');

    expect($response['granularity'])->toBe('month');
    $series = collect($response['series'])->keyBy('period');
    expect($series->get('2026-03')['revenue'])->toBe(10_000);
    expect($series->get('2026-03')['orders'])->toBe(2);
    expect($series->get('2026-04')['revenue'])->toBe(7_000);
});

it('backfills empty period buckets across the window', function () {
    makePosClosedOrder($this->shop->id, $this->brand->id, $this->orgId, '2026-05-03 12:00:00', 1_000, 1);

    $response = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/summary?granularity=day&from=2026-05-01&to=2026-05-05')
        ->assertOk()
        ->json('data');

    $periods = collect($response['series'])->pluck('period')->all();
    expect($periods)->toEqual([
        '2026-05-01',
        '2026-05-02',
        '2026-05-03',
        '2026-05-04',
        '2026-05-05',
    ]);
    expect(collect($response['series'])->keyBy('period')->get('2026-05-02')['revenue'])->toBe(0);
});

it('rejects an inverted window with 422', function () {
    $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/summary?granularity=day&from=2026-05-31&to=2026-05-01')
        ->assertStatus(422);
});

it('exposes 税抜 net / 税込 gross / per-rate tax reconciling the summary vs by-product convention', function () {
    // plan-043 T4.6 — one closed order: bentō ¥1,000 @8% (tax ¥80) + beer
    // ¥500 @10% (tax ¥50). gross = total_amount = 1,630, net = Σ subtotal =
    // 1,500, tax = 130.
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'status' => 'closed',
        'total_amount' => 1_630,
        'guest_count' => 1,
        'created_at' => '2026-05-01 12:00:00',
        'closed_at' => '2026-05-01 12:00:00',
    ]);

    $bento = ProductSku::factory()->create();
    $beer = ProductSku::factory()->create();
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $bento->id,
        'quantity' => 1,
        'unit_price' => 1_000,
        'subtotal' => 1_000,
        'tax_rate' => 8,
        'tax_amount' => 80,
        'status' => 'served',
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $beer->id,
        'quantity' => 1,
        'unit_price' => 500,
        'subtotal' => 500,
        'tax_rate' => 10,
        'tax_amount' => 50,
        'status' => 'served',
    ]);

    $data = $this->actingAs($this->manager)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/revenue/summary?granularity=day&from=2026-05-01&to=2026-05-01')
        ->assertOk()
        ->json('data');

    // Gross (税込) stays the legacy total-inclusive revenue.
    expect($data['kpis']['revenue'])->toBe(1_630);
    expect($data['kpis']['gross'])->toBe(1_630);
    // Net (税抜) = Σ subtotal, the by-product convention.
    expect($data['kpis']['net'])->toBe(1_500);
    expect($data['kpis']['tax'])->toBe(130);
    // gross = net + tax reconciles the two conventions.
    expect($data['kpis']['net'] + $data['kpis']['tax'])->toBe($data['kpis']['gross']);

    $byRate = collect($data['kpis']['by_tax_rate'])->keyBy(fn ($r) => (int) $r['rate']);
    expect($byRate->get(8)['taxable'])->toBe(1_000);
    expect($byRate->get(8)['tax'])->toBe(80);
    expect($byRate->get(10)['taxable'])->toBe(500);
    expect($byRate->get(10)['tax'])->toBe(50);
});
