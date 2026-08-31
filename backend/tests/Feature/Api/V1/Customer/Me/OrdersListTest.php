<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\File;
use App\Models\OrderPayment;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Pin the clock to midday. These fixtures build timestamps from now()±offsets
    // and then assert against a "today" filter; run them near midnight and the
    // offset lands on the previous date, so the filter legitimately finds nothing.
    // See #1091 — this is the same date-boundary class as the Sunday-only and
    // 23:59-only failures fixed in 6e124f62.
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create();
    $this->branch = Branch::factory()->create();

    $this->customer = Customer::factory()->selfRegistered()->create([
        'password' => 'password',
    ]);
    $this->token = $this->customer->createToken('test')->plainTextToken;
});

it('returns all orders for the customer', function () {
    CustomerOrder::factory()->closed()->count(5)->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);

    $response = $this->getJson('/api/v1/customer/me/orders', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonStructure([
            'data' => [['id', 'order_code', 'order_type', 'status', 'total_amount', 'created_at']],
            'meta' => ['has_more', 'next_cursor'],
        ]);
});

it('paginates with cursor when more than 20 orders', function () {
    CustomerOrder::factory()->closed()->count(25)->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);

    $response = $this->getJson('/api/v1/customer/me/orders', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.has_more', true);

    $cursor = $response->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();

    // Verify cursor is valid base64-encoded JSON with expected fields
    $decoded = json_decode(base64_decode($cursor), true);
    expect($decoded)->toHaveKeys(['created_at', 'id']);
});

it('cursor second page currently returns duplicate rows (known bug — see PR notes)', function () {
    // CHARACTERIZATION of a real defect in cursor pagination.
    //
    // The cursor encodes created_at via toIso8601String() ("...T..+00:00")
    // but CustomerOrderHistoryService compares it against the raw datetime
    // column ("2026-07-11 10:19:00"). String-comparing the two, ' ' (0x20)
    // sorts before 'T' (0x54), so `created_at < <cursor.created_at>` matches
    // EVERY row. Page 2 therefore re-returns the same top-20 with the same
    // next_cursor — an infinite loop; the "remaining 5" are never reachable.
    //
    // CORRECT behaviour (flip this test when the service is fixed to encode/
    // compare a matching datetime format): page 2 returns the remaining 5,
    // has_more=false, next_cursor=null, and no row overlaps page 1.
    //
    // 25 distinct-timestamp orders so the ordering is fully deterministic.
    for ($i = 0; $i < 25; $i++) {
        CustomerOrder::factory()->closed()->create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->organization->id,
            'created_at' => now()->subMinutes($i),
        ]);
    }

    // Page 1 — 20 items, has_more=true, a cursor set.
    $page1 = $this->getJson('/api/v1/customer/me/orders', [
        'Authorization' => "Bearer {$this->token}",
    ]);
    $page1->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.has_more', true);

    $cursor = $page1->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();
    $page1Ids = collect($page1->json('data'))->pluck('id');

    // Page 2 — BUG: identical 20 rows, still has_more, identical cursor.
    $page2 = $this->getJson('/api/v1/customer/me/orders?cursor='.urlencode($cursor), [
        'Authorization' => "Bearer {$this->token}",
    ]);
    $page2->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.has_more', true)
        ->assertJsonPath('meta.next_cursor', $cursor);

    $page2Ids = collect($page2->json('data'))->pluck('id');

    // Every page-1 row reappears on page 2 (full overlap) — the loop never advances.
    expect($page1Ids->intersect($page2Ids))->toHaveCount(20);
    expect($page2Ids->values()->all())->toBe($page1Ids->values()->all());
});

it('eager-loads the product chain the order card renders', function () {
    // Was a CHARACTERIZATION of a real defect: list() eager-loaded branch +
    // items + payments but NOT items.productSku.product, while
    // CustomerOrderSummaryResource reads $item->productSku?->product?->name per
    // line (N+1) and resolveItemImageUrl() checks relationLoaded('galleryFirst')
    // / ('thumbnail') — which, unloaded, returned image_url = null for EVERY
    // order. Harmless while /account/orders showed no photo; it became a blank
    // thumbnail on every card once that page adopted the /orders card layout.
    $order = CustomerOrder::factory()->closed()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);
    CustomerOrderItem::factory()->count(2)->create(['customer_order_id' => $order->id]);
    OrderPayment::factory()->count(1)->create(['customer_order_id' => $order->id]);

    Model::preventLazyLoading(true);

    try {
        $response = $this->withoutExceptionHandling()->getJson(
            '/api/v1/customer/me/orders',
            ['Authorization' => "Bearer {$this->token}"],
        );
    } finally {
        Model::preventLazyLoading(false);
    }

    $response->assertOk()
        ->assertJsonCount(2, 'data.0.items')
        // `image_url` must be RESOLVED (null only when the product genuinely has
        // no photo), not skipped because the relation was never loaded.
        ->assertJsonStructure([
            'data' => [['items' => [['id', 'product_sku_id', 'name', 'image_url', 'qty', 'status']]]],
        ]);

    expect($response->json('data.0.items.0.name'))->not->toBeNull();
});

it('resolves the item photo the card shows as its thumbnail', function () {
    $order = CustomerOrder::factory()->closed()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);
    $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id]);

    $product = $item->productSku->product;
    File::factory()->create([
        'fileable_type' => $product->getMorphClass(),
        'fileable_id' => $product->id,
        'collection' => 'gallery',
    ]);

    $response = $this->getJson('/api/v1/customer/me/orders', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk();
    expect($response->json('data.0.items.0.image_url'))->not->toBeNull();
});

it('filters by status', function () {
    CustomerOrder::factory()->closed()->count(3)->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);
    CustomerOrder::factory()->voided()->count(2)->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);

    $response = $this->getJson('/api/v1/customer/me/orders?status=closed', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()->assertJsonCount(3, 'data');
});

it('filters by date range', function () {
    CustomerOrder::factory()->closed()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
        'created_at' => '2026-04-01 10:00:00',
    ]);
    CustomerOrder::factory()->closed()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
        'created_at' => '2026-04-20 10:00:00',
    ]);

    $response = $this->getJson('/api/v1/customer/me/orders?from=2026-04-01&to=2026-04-15', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()->assertJsonCount(1, 'data');
});

it('only returns orders belonging to the authenticated customer', function () {
    $otherCustomer = Customer::factory()->selfRegistered()->create(['password' => 'password']);

    CustomerOrder::factory()->closed()->count(3)->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);
    CustomerOrder::factory()->closed()->count(2)->create([
        'customer_id' => $otherCustomer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);

    $response = $this->getJson('/api/v1/customer/me/orders', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()->assertJsonCount(3, 'data');
});

it('returns 401 without token', function () {
    $this->getJson('/api/v1/customer/me/orders')->assertUnauthorized();
});

it('returns empty data for non-existent branch_slug filter', function () {
    CustomerOrder::factory()->closed()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);

    $response = $this->getJson('/api/v1/customer/me/orders?branch_slug=nonexistent', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()->assertJsonCount(0, 'data');
});
