<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\StockLevel;
use App\Models\Table;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\Zone;
use Illuminate\Support\Str;

// ── Shared setup ─────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'test-shop',
        'is_active' => true,
        // #1091 — the dashboard's "today" is now the SHOP's day. These tests
        // create orders at the server clock, so pin the shop to UTC to keep
        // them about KPI maths; shop-day behaviour is covered by
        // tests/Feature/Timezone/BusinessTimezoneContractTest.php.
        'timezone' => 'UTC',
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/shops/{$this->shop->slug}/dashboard";
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeShopClosedOrder(float $total, ?string $createdAt = null): CustomerOrder
{
    return CustomerOrder::factory()->closed()->create([
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'branch_id' => test()->shop->id,
        'total_amount' => $total,
        'created_at' => $createdAt ?? now(),
    ]);
}

function makeShopProductWithCategory(): array
{
    $category = Category::factory()->create([
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'name' => 'Test Category',
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'name' => 'Test Product',
        'status' => 'approved',
    ]);

    $product->categories()->attach($category->id);

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    return [$product, $sku, $category];
}

// ── /dashboard/kpis ──────────────────────────────────────────────────────────

describe('kpis', function () {
    it('returns the correct JSON structure', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'revenue' => ['value', 'delta_pct'],
                    'orders' => ['value', 'delta_pct'],
                    'table_occupancy' => ['occupied', 'total'],
                    'low_stock' => ['value'],
                ],
            ]);
    });

    it('returns zeros when there are no orders', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.revenue.value', 0)
            ->assertJsonPath('data.orders.value', 0);
    });

    it('supports a Platform branch that is not assigned to a brand', function () {
        $this->shop->update(['console_brand_id' => null]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.revenue.value', 0)
            ->assertJsonPath('data.orders.value', 0);
    });

    it('sums only closed orders for today revenue', function () {
        makeShopClosedOrder(5000);
        CustomerOrder::factory()->open()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->shop->id,
            'total_amount' => 9999,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.revenue.value', 5000);
    });

    it('#821 E9: decimal-currency revenue rounds instead of truncating', function () {
        // A USD-style total (decimal:2 cast) used to be `(int)`-truncated:
        // 25.99 displayed as 25. Money rounds half-up.
        makeShopClosedOrder(25.99);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.revenue.value', 26);
    });

    it('exposes 税抜 net / 税込 gross / per-rate tax for today revenue', function () {
        // plan-043 T4.6 — bentō ¥1,000 @8% (tax ¥80) + beer ¥500 @10% (tax
        // ¥50). gross = total_amount = 1,630 ; net = Σ subtotal = 1,500.
        $order = makeShopClosedOrder(1630);
        $bento = ProductSku::factory()->create();
        $beer = ProductSku::factory()->create();
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $bento->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'subtotal' => 1000,
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

        $data = $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->json('data');

        expect($data['revenue']['value'])->toBe(1630);
        expect($data['revenue']['gross'])->toBe(1630);
        expect($data['revenue']['net'])->toBe(1500);
        expect($data['revenue']['tax'])->toBe(130);

        $byRate = collect($data['revenue']['by_tax_rate'])->keyBy(fn ($r) => (int) $r['rate']);
        expect($byRate->get(8)['tax'])->toBe(80);
        expect($byRate->get(10)['tax'])->toBe(50);
    });

    it('excludes voided orders from order count', function () {
        makeShopClosedOrder(1000);
        CustomerOrder::factory()->voided()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->shop->id,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.orders.value', 1);
    });

    it('counts occupied and reserved tables', function () {
        $zone = Zone::factory()->for($this->shop, 'branch')->create(['organization_id' => $this->orgId]);

        Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create(['organization_id' => $this->orgId, 'status' => 'free', 'is_active' => true]);
        Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create(['organization_id' => $this->orgId, 'status' => 'occupied', 'is_active' => true]);
        Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create(['organization_id' => $this->orgId, 'status' => 'reserved', 'is_active' => true]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk();

        expect($response->json('data.table_occupancy.total'))->toBe(3);
        expect($response->json('data.table_occupancy.occupied'))->toBe(2);
    });

    it('counts low-stock items via warehouse', function () {
        $warehouse = Warehouse::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->shop->id,
            'is_active' => true,
        ]);

        StockLevel::factory()->create(['warehouse_id' => $warehouse->id, 'quantity' => 2, 'min_stock' => 5]);
        StockLevel::factory()->create(['warehouse_id' => $warehouse->id, 'quantity' => 10, 'min_stock' => 5]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.low_stock.value', 1);
    });

    it('returns positive delta_pct when today revenue exceeds yesterday', function () {
        makeShopClosedOrder(1000, now()->subDay()->startOfDay()->toDateTimeString());
        makeShopClosedOrder(2000, now()->startOfDay()->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk();

        expect($response->json('data.revenue.delta_pct'))->toBeGreaterThan(0);
    });

    it('returns 401 when unauthenticated', function () {
        $this->getJson("{$this->base}/kpis")->assertUnauthorized();
    });

    it('returns 403 for cross-org access', function () {
        $otherUser = User::factory()->create(['console_organization_id' => (string) Str::uuid()]);

        $this->actingAs($otherUser)
            ->getJson("{$this->base}/kpis")
            ->assertForbidden();
    });

    it('returns 403 when a same-org user is scoped to another branch', function () {
        $role = $this->user->roles()->firstOrFail();
        $otherShop = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
        ]);
        $this->user->roles()->detach();
        $this->user->assignRole($role, $this->orgId, $otherShop->id);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertForbidden();
    });
});

// ── /dashboard/revenue-trend ─────────────────────────────────────────────────

describe('revenue-trend', function () {
    it('returns the correct JSON structure', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-trend")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['day', 'revenue', 'orders'],
                ],
            ]);
    });

    it('returns data grouped by day over last 7 days', function () {
        makeShopClosedOrder(3000, now()->startOfDay()->toDateTimeString());
        makeShopClosedOrder(2000, now()->subDays(3)->startOfDay()->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-trend")
            ->assertOk();

        $days = collect($response->json('data'))->pluck('day');
        expect($days)->toContain(now()->format('Y-m-d'));
    });

    it('excludes non-closed orders from revenue', function () {
        CustomerOrder::factory()->open()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->shop->id,
            'total_amount' => 9999,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-trend")
            ->assertOk();

        $total = collect($response->json('data'))->sum('revenue');
        expect($total)->toBe(0);
    });

    it('returns 401 when unauthenticated', function () {
        $this->getJson("{$this->base}/revenue-trend")->assertUnauthorized();
    });
});

// ── /dashboard/table-status ──────────────────────────────────────────────────

describe('table-status', function () {
    it('returns the correct JSON structure', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/table-status")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['status', 'count'],
                ],
            ]);
    });

    it('returns all 5 statuses with counts', function () {
        $zone = Zone::factory()->for($this->shop, 'branch')->create(['organization_id' => $this->orgId]);

        foreach (['free', 'occupied', 'reserved', 'cleaning', 'out_of_service'] as $status) {
            Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
                'organization_id' => $this->orgId,
                'status' => $status,
                'is_active' => true,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/table-status")
            ->assertOk();

        $statuses = collect($response->json('data'))->pluck('count', 'status');
        expect($statuses['free'])->toBe(1);
        expect($statuses['occupied'])->toBe(1);
    });

    it('returns zeros for statuses with no tables', function () {
        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/table-status")
            ->assertOk();

        $counts = collect($response->json('data'))->sum('count');
        expect($counts)->toBe(0);
    });

    it('returns 401 when unauthenticated', function () {
        $this->getJson("{$this->base}/table-status")->assertUnauthorized();
    });
});

// ── /dashboard/top-items ──────────────────────────────────────────────────────

describe('top-items', function () {
    it('returns the correct JSON structure', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/top-items")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['product_id', 'product_name', 'category_name', 'sold', 'revenue', 'trend'],
                ],
            ]);
    });

    it('returns empty array when no orders today', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/top-items")
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('ranks products by units sold descending', function () {
        [$product1, $sku1] = makeShopProductWithCategory();
        [$product2, $sku2] = makeShopProductWithCategory();

        $order = makeShopClosedOrder(5000);

        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $sku1->id,
            'quantity' => 10,
            'unit_price' => 200,
            'subtotal' => 2000,
            'status' => 'served',
        ]);

        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $sku2->id,
            'quantity' => 3,
            'unit_price' => 1000,
            'subtotal' => 3000,
            'status' => 'served',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/top-items?limit=5")
            ->assertOk();

        expect($response->json('data.0.sold'))->toBeGreaterThanOrEqual($response->json('data.1.sold'));
    });

    it('marks trend as up when sold today >= yesterday', function () {
        [$product, $sku] = makeShopProductWithCategory();

        $todayOrder = makeShopClosedOrder(1000);
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $todayOrder->id,
            'product_sku_id' => $sku->id,
            'quantity' => 5,
            'unit_price' => 200,
            'subtotal' => 1000,
            'status' => 'served',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/top-items")
            ->assertOk();

        expect($response->json('data.0.trend'))->toBe('up');
    });

    it('returns 401 when unauthenticated', function () {
        $this->getJson("{$this->base}/top-items")->assertUnauthorized();
    });
});

// ── /dashboard/production-queue ───────────────────────────────────────────────

describe('production-queue', function () {
    it('returns the correct JSON structure', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/production-queue")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['order_code', 'product_name', 'quantity', 'status'],
                ],
            ]);
    });

    it('returns empty array when no open orders today', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/production-queue")
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('returns pending items from open orders', function () {
        [$product, $sku] = makeShopProductWithCategory();

        $openOrder = CustomerOrder::factory()->open()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->shop->id,
            'created_at' => now(),
        ]);

        CustomerOrderItem::factory()->create([
            'customer_order_id' => $openOrder->id,
            'product_sku_id' => $sku->id,
            'quantity' => 2,
            'unit_price' => 500,
            'subtotal' => 1000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/production-queue")
            ->assertOk();

        expect($response->json('data'))->not->toBeEmpty();
        expect($response->json('data.0.status'))->toBe('pending');
    });

    it('excludes served and voided items', function () {
        [$product, $sku] = makeShopProductWithCategory();

        $order = CustomerOrder::factory()->open()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->shop->id,
            'created_at' => now(),
        ]);

        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $sku->id,
            'quantity' => 1,
            'unit_price' => 500,
            'subtotal' => 500,
            'status' => 'served',
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/production-queue")
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('returns 401 when unauthenticated', function () {
        $this->getJson("{$this->base}/production-queue")->assertUnauthorized();
    });
});

// ── /dashboard/recent-orders ──────────────────────────────────────────────────

describe('recent-orders', function () {
    it('returns the correct JSON structure', function () {
        makeShopClosedOrder(5000);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'order_code', 'table_code', 'items_count', 'total_amount', 'status', 'created_at'],
                ],
            ]);
    });

    it('returns empty array when no orders', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('maps closed status to completed', function () {
        makeShopClosedOrder(5000);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk();

        expect($response->json('data.0.status'))->toBe('completed');
    });

    it('maps voided status to cancelled', function () {
        CustomerOrder::factory()->voided()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->shop->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk();

        expect($response->json('data.0.status'))->toBe('cancelled');
    });

    it('scopes orders to this branch only', function () {
        $otherBranch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'is_active' => true,
        ]);

        CustomerOrder::factory()->closed()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $otherBranch->id,
            'total_amount' => 9999,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('respects the limit parameter', function () {
        for ($i = 0; $i < 10; $i++) {
            makeShopClosedOrder(1000);
        }

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders?limit=3")
            ->assertOk();

        expect(count($response->json('data')))->toBe(3);
    });

    it('returns 401 when unauthenticated', function () {
        $this->getJson("{$this->base}/recent-orders")->assertUnauthorized();
    });
});
