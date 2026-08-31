<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// ── Shared setup ─────────────────────────────────────────────────────────────

beforeEach(function () {
    // ĐÓNG BĂNG ĐỒNG HỒ — bắt buộc, không phải cho gọn (#1412).
    //
    // Mọi khẳng định trong file này là "kỳ này vs kỳ trước", nên chúng phụ thuộc
    // hôm nay là ngày mấy. Không đóng băng thì suite XANH 30 ngày và ĐỎ vào ngày
    // 31 — vì `now()->subMonth()` từ 31/7 ra 1/7 (31/6 không tồn tại nên Carbon
    // tràn tới trước), làm kỳ trước trùng kỳ hiện tại. Đó chính là lỗi sản phẩm
    // mà file này đáng lẽ phải bắt, nhưng nó chỉ tình cờ đỏ đúng một ngày mỗi
    // tháng — tức 29/30 lần chạy nó nói dối.
    //
    // Chọn ngày 15 có chủ ý: xa cả hai biên tháng, nên fixture không bao giờ
    // rơi nhầm rổ dù tháng 28, 29, 30 hay 31 ngày.
    Carbon::setTestNow(Carbon::parse('2026-05-15 10:00:00'));

    $this->orgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'test-brand',
        'is_active' => true,
    ]);

    $this->branch = Branch::factory()->create([
        'console_brand_id' => $this->brand->console_brand_id,
        'console_organization_id' => $this->orgId,
        'name' => 'Main Branch',
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    grantOrgAccess($this->user, $this->orgId);

    $this->base = "/api/v1/hq/{$this->brand->slug}/dashboard";
});

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Create a closed order tied to the test brand/org/branch with a specific total. */
function makeClosedOrder(float $total, ?string $createdAt = null): CustomerOrder
{
    return CustomerOrder::factory()->closed()->create([
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'total_amount' => $total,
        'created_at' => $createdAt ?? now(),
    ]);
}

/** Create a product attached to a category, return [product, sku, category]. */
function makeProductWithCategory(): array
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
                    'products' => ['value', 'delta_pct'],
                    'shops' => ['value', 'delta_pct'],
                ],
            ]);
    });

    it('returns zeros when there are no orders', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.revenue.value', 0)
            ->assertJsonPath('data.orders.value', 0)
            ->assertJsonPath('data.shops.value', 0);
    });

    it('sums only closed orders for revenue', function () {
        makeClosedOrder(5000);
        CustomerOrder::factory()->open()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'total_amount' => 9999,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.revenue.value', 5000);
    });

    it('exposes 税抜 net / 税込 gross / per-rate tax for the period revenue', function () {
        // plan-043 T4.6 — bentō ¥1,000 @8% (tax ¥80) + beer ¥500 @10% (tax
        // ¥50). gross = total_amount = 1,630 ; net = Σ subtotal = 1,500.
        $order = makeClosedOrder(1630);
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
        makeClosedOrder(1000);
        CustomerOrder::factory()->voided()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'total_amount' => 500,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.orders.value', 1);
    });

    it('counts approved products', function () {
        Product::factory()->count(3)->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'status' => 'approved',
        ]);
        Product::factory()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'status' => 'draft',
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis")
            ->assertOk()
            ->assertJsonPath('data.products.value', 3);
    });

    it('returns positive delta_pct when current revenue exceeds previous period', function () {
        // Previous month: ¥1,000
        makeClosedOrder(1000, now()->subMonth()->startOfMonth()->toDateTimeString());

        // Current month: ¥2,000  (+100%)
        makeClosedOrder(2000, now()->startOfMonth()->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis?period=month")
            ->assertOk();

        expect($response->json('data.revenue.delta_pct'))->toBeGreaterThan(0);
    });

    it('returns negative delta_pct when current revenue is lower than previous period', function () {
        // Previous month: ¥5,000
        makeClosedOrder(5000, now()->subMonth()->startOfMonth()->toDateTimeString());

        // Current month: ¥1,000  (-80%)
        makeClosedOrder(1000, now()->startOfMonth()->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis?period=month")
            ->assertOk();

        expect($response->json('data.revenue.delta_pct'))->toBeLessThan(0);
    });

    // ── Ghim lỗi tràn tháng (#1412) ──────────────────────────────────────────
    //
    // Ca này TỰ đặt lại đồng hồ về ngày 31 — cố ý khác `beforeEach` (ngày 15).
    // Ngày 15 an toàn nên mọi test khác XANH kể cả khi code sai; chỉ ngày 31 mới
    // lộ ra, và không có ca này thì bản vá không có gì canh.
    //
    // Vì sao 31/7: `Carbon::parse('2026-07-31')->subMonth()` ra **2026-07-01**,
    // không phải tháng 6 — 31/6 không tồn tại nên Carbon tràn TỚI TRƯỚC. Code cũ
    // dùng `subMonth()->startOfMonth()` nên "kỳ trước" thành đúng kỳ hiện tại, và
    // dashboard so tháng này với chính nó.
    it('#1412: ngày 31 vẫn so đúng với THÁNG TRƯỚC, không so với chính nó', function () {
        Carbon::setTestNow(Carbon::parse('2026-07-31 10:00:00'));

        // Tháng 6: ¥5.000 — phải rơi vào KỲ TRƯỚC.
        makeClosedOrder(5000, '2026-06-10 12:00:00');
        // Tháng 7: ¥1.000 — kỳ hiện tại, thấp hơn hẳn ⇒ delta phải ÂM.
        makeClosedOrder(1000, '2026-07-05 12:00:00');

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis?period=month")
            ->assertOk();

        // Code cũ: cả hai đơn rơi cùng một rổ ⇒ previous = 0 ⇒ delta KHÔNG âm.
        expect($response->json('data.revenue.delta_pct'))->toBeLessThan(0);
    });

    it('supports period=week', function () {
        makeClosedOrder(3000, now()->startOfWeek()->toDateTimeString());

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis?period=week")
            ->assertOk()
            ->assertJsonPath('data.revenue.value', 3000);
    });

    it('supports period=year', function () {
        makeClosedOrder(7000, now()->startOfYear()->toDateTimeString());

        $this->actingAs($this->user)
            ->getJson("{$this->base}/kpis?period=year")
            ->assertOk()
            ->assertJsonPath('data.revenue.value', 7000);
    });
});

// ── /dashboard/revenue-chart ──────────────────────────────────────────────────

describe('revenue-chart', function () {
    it('returns time-series data with period, revenue, orders keys', function () {
        makeClosedOrder(10000);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-chart")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['period', 'revenue', 'orders']],
            ]);
    });

    it('groups by month by default', function () {
        makeClosedOrder(5000, now()->startOfMonth()->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-chart")
            ->assertOk();

        $period = $response->json('data.0.period');
        // YYYY-MM format
        expect($period)->toMatch('/^\d{4}-\d{2}$/');
    });

    it('groups by year when group_by=year', function () {
        makeClosedOrder(5000, now()->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-chart?group_by=year")
            ->assertOk();

        $period = $response->json('data.0.period');
        // YYYY format
        expect($period)->toMatch('/^\d{4}$/');
    });

    it('groups by week when group_by=week', function () {
        makeClosedOrder(5000, now()->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-chart?group_by=week")
            ->assertOk();

        $period = $response->json('data.0.period');
        // YYYY-Www format
        expect($period)->toMatch('/^\d{4}-W\d{2}$/');
    });

    it('respects date_from and date_to filters', function () {
        // Order within range
        makeClosedOrder(8000, '2026-01-15 12:00:00');
        // Order outside range
        makeClosedOrder(1000, '2025-12-01 12:00:00');

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-chart?date_from=2026-01-01&date_to=2026-01-31")
            ->assertOk();

        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.revenue'))->toBe(8000);
    });

    it('returns empty array when no orders in range', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-chart?date_from=2020-01-01&date_to=2020-01-31")
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('accumulates multiple orders in the same period', function () {
        makeClosedOrder(3000, now()->startOfMonth()->toDateTimeString());
        makeClosedOrder(4000, now()->startOfMonth()->addDays(2)->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/revenue-chart")
            ->assertOk();

        $currentPeriodData = collect($response->json('data'))
            ->firstWhere('period', now()->format('Y-m'));

        expect($currentPeriodData['revenue'])->toBe(7000);
        expect($currentPeriodData['orders'])->toBe(2);
    });
});

// ── /dashboard/category-sales ─────────────────────────────────────────────────

describe('category-sales', function () {
    it('returns category breakdown with percentage', function () {
        [$product, $sku, $category] = makeProductWithCategory();
        $order = makeClosedOrder(2000);

        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $sku->id,
            'quantity' => 2,
            'unit_price' => 1000,
            'subtotal' => 2000,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/category-sales")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['category_id', 'category_name', 'revenue', 'percentage']],
            ])
            ->assertJsonPath('data.0.percentage', 100)
            ->assertJsonPath('data.0.category_name', 'Test Category');
    });

    it('returns percentages that sum to 100 across multiple categories', function () {
        [$productA, $skuA] = makeProductWithCategory();
        [$productB, $skuB] = makeProductWithCategory();

        $order = makeClosedOrder(6000);
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $skuA->id,
            'quantity' => 2,
            'unit_price' => 1000,
            'subtotal' => 2000,
        ]);
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $skuB->id,
            'quantity' => 4,
            'unit_price' => 1000,
            'subtotal' => 4000,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/category-sales")
            ->assertOk();

        $total = collect($response->json('data'))->sum('percentage');
        expect($total)->toBe(100.0);
    });

    it('returns empty array when there are no closed orders', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/category-sales")
            ->assertOk()
            ->assertJsonPath('data', []);
    });
});

// ── /dashboard/shop-performance ───────────────────────────────────────────────

describe('shop-performance', function () {
    it('returns branch name, slug, revenue, and target', function () {
        makeClosedOrder(5000);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/shop-performance")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['branch_id', 'branch_slug', 'branch_name', 'revenue', 'target']],
            ])
            ->assertJsonPath('data.0.branch_name', 'Main Branch')
            ->assertJsonPath('data.0.branch_slug', $this->branch->slug)
            ->assertJsonPath('data.0.revenue', 5000);
    });

    it('target is at least as high as current revenue when no history exists', function () {
        makeClosedOrder(3000);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/shop-performance")
            ->assertOk();

        $row = $response->json('data.0');
        expect($row['target'])->toBeGreaterThanOrEqual($row['revenue']);
    });

    it('target is avg of last 3 months times 1.1 when history exists', function () {
        // Three previous months: ¥1,000 each → avg ¥1,000 → target ¥1,100.
        // subMonthsNoOverflow guards against Carbon collapsing subMonths(2) and (1)
        // onto the same month when today is the 29th/30th/31st (e.g. 2026-04-29
        // → subMonths(2) overflows Feb 28 → 2026-03-01).
        foreach (range(1, 3) as $m) {
            makeClosedOrder(1000, now()->subMonthsNoOverflow($m)->startOfMonth()->toDateTimeString());
        }
        // Current month
        makeClosedOrder(800, now()->startOfMonth()->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/shop-performance")
            ->assertOk();

        expect($response->json('data.0.target'))->toBe(1100);
    });

    it('returns empty array when there are no orders', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/shop-performance")
            ->assertOk()
            ->assertJsonPath('data', []);
    });

    it('still resolves the name of a soft-deleted branch with historical orders', function () {
        $deletedBranch = Branch::factory()->create([
            'console_brand_id' => $this->brand->console_brand_id,
            'console_organization_id' => $this->orgId,
            'name' => 'Closed Branch',
        ]);

        CustomerOrder::factory()->closed()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $deletedBranch->id,
            'total_amount' => 7000,
            'created_at' => now(),
        ]);

        $deletedBranch->delete();

        $row = collect(
            $this->actingAs($this->user)
                ->getJson("{$this->base}/shop-performance")
                ->assertOk()
                ->json('data')
        )->firstWhere('branch_id', $deletedBranch->id);

        expect($row)->not->toBeNull();
        expect($row['branch_name'])->toBe('Closed Branch');
        expect($row['branch_slug'])->toBe($deletedBranch->slug);
        expect($row['revenue'])->toBe(7000);
    });
});

// ── /dashboard/top-products ───────────────────────────────────────────────────

describe('top-products', function () {
    it('returns products sorted by sold quantity descending', function () {
        [$productA, $skuA] = makeProductWithCategory();
        [$productB, $skuB] = makeProductWithCategory();

        $order = makeClosedOrder(9000);
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $skuA->id,
            'quantity' => 10,
            'unit_price' => 500,
            'subtotal' => 5000,
        ]);
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $skuB->id,
            'quantity' => 4,
            'unit_price' => 1000,
            'subtotal' => 4000,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/top-products")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['product_id', 'product_name', 'category_name', 'sold', 'revenue', 'trend']],
            ]);

        expect($response->json('data.0.sold'))->toBe(10);
        expect($response->json('data.1.sold'))->toBe(4);
    });

    it('sets trend=up when current period sold >= previous period', function () {
        [$product, $sku] = makeProductWithCategory();

        // Previous month: qty 2
        $prevOrder = makeClosedOrder(2000, now()->subMonth()->startOfMonth()->toDateTimeString());
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $prevOrder->id,
            'product_sku_id' => $sku->id,
            'quantity' => 2,
            'unit_price' => 1000,
            'subtotal' => 2000,
        ]);

        // Current month: qty 5 (more → up)
        $currOrder = makeClosedOrder(5000, now()->startOfMonth()->toDateTimeString());
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $currOrder->id,
            'product_sku_id' => $sku->id,
            'quantity' => 5,
            'unit_price' => 1000,
            'subtotal' => 5000,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/top-products?period=month")
            ->assertOk();

        expect($response->json('data.0.trend'))->toBe('up');
    });

    it('sets trend=down when current period sold < previous period', function () {
        [$product, $sku] = makeProductWithCategory();

        // Previous month: qty 10
        $prevOrder = makeClosedOrder(10000, now()->subMonth()->startOfMonth()->toDateTimeString());
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $prevOrder->id,
            'product_sku_id' => $sku->id,
            'quantity' => 10,
            'unit_price' => 1000,
            'subtotal' => 10000,
        ]);

        // Current month: qty 2 (fewer → down)
        $currOrder = makeClosedOrder(2000, now()->startOfMonth()->toDateTimeString());
        CustomerOrderItem::factory()->create([
            'customer_order_id' => $currOrder->id,
            'product_sku_id' => $sku->id,
            'quantity' => 2,
            'unit_price' => 1000,
            'subtotal' => 2000,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/top-products?period=month")
            ->assertOk();

        expect($response->json('data.0.trend'))->toBe('down');
    });

    it('respects the limit parameter', function () {
        foreach (range(1, 8) as $i) {
            [$product, $sku] = makeProductWithCategory();
            $order = makeClosedOrder($i * 1000);
            CustomerOrderItem::factory()->create([
                'customer_order_id' => $order->id,
                'product_sku_id' => $sku->id,
                'quantity' => $i,
                'unit_price' => 1000,
                'subtotal' => $i * 1000,
            ]);
        }

        $this->actingAs($this->user)
            ->getJson("{$this->base}/top-products?limit=3")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });

    it('returns empty array when there are no orders', function () {
        $this->actingAs($this->user)
            ->getJson("{$this->base}/top-products")
            ->assertOk()
            ->assertJsonPath('data', []);
    });
});

// ── /dashboard/recent-orders ──────────────────────────────────────────────────

describe('recent-orders', function () {
    it('returns the most recent orders with correct structure', function () {
        makeClosedOrder(1000);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'order_code', 'table_code', 'items_count', 'total_amount', 'status', 'created_at']],
            ]);
    });

    it('maps status closed → completed', function () {
        makeClosedOrder(1000);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'completed');
    });

    it('maps status voided → cancelled', function () {
        CustomerOrder::factory()->voided()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'total_amount' => 500,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'cancelled');
    });

    it('maps open/dining/checkout/paying statuses → in_progress', function (string $status) {
        CustomerOrder::factory()->$status()->create([
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'total_amount' => 500,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'in_progress');
    })->with(['open', 'dining', 'checkout', 'paying']);

    it('returns orders sorted by created_at descending', function () {
        makeClosedOrder(1000, now()->subMinutes(30)->toDateTimeString());
        makeClosedOrder(2000, now()->subMinutes(5)->toDateTimeString());

        $response = $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk();

        expect($response->json('data.0.total_amount'))->toBe(2000);
        expect($response->json('data.1.total_amount'))->toBe(1000);
    });

    it('counts items correctly', function () {
        [$product, $sku] = makeProductWithCategory();
        $order = makeClosedOrder(3000);

        CustomerOrderItem::factory()->count(3)->create([
            'customer_order_id' => $order->id,
            'product_sku_id' => $sku->id,
        ]);

        $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders")
            ->assertOk()
            ->assertJsonPath('data.0.items_count', 3);
    });

    it('respects the limit parameter', function () {
        foreach (range(1, 8) as $i) {
            makeClosedOrder($i * 500, now()->subMinutes($i)->toDateTimeString());
        }

        $this->actingAs($this->user)
            ->getJson("{$this->base}/recent-orders?limit=3")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    });
});

// ── Authorization ─────────────────────────────────────────────────────────────

describe('authorization', function () {
    it('returns 401 for unauthenticated requests', function (string $endpoint) {
        $this->getJson("{$this->base}/{$endpoint}")->assertUnauthorized();
    })->with(['kpis', 'revenue-chart', 'category-sales', 'shop-performance', 'top-products', 'recent-orders']);

    it('returns 403 when accessing a brand from another organization', function (string $endpoint) {
        $otherOrgId = (string) Str::uuid();
        Organization::factory()->create([
            'id' => $otherOrgId,
            'console_organization_id' => $otherOrgId,
        ]);
        $otherBrand = Brand::factory()->create([
            'console_organization_id' => $otherOrgId,
            'slug' => 'other-brand',
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/hq/{$otherBrand->slug}/dashboard/{$endpoint}")
            ->assertForbidden();
    })->with(['kpis', 'revenue-chart', 'category-sales', 'shop-performance', 'top-products', 'recent-orders']);
});
