<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->organization->id,
        'review_up_count' => 0,
        'review_total_count' => 0,
    ]);

    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
    ]);

    $this->order = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $this->orderItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $this->order->id,
        'product_sku_id' => $this->sku->id,
        'status' => 'served',
    ]);
});

// =========================================================================
//  Happy path
// =========================================================================

it('creates a single review and increments product aggregates', function () {
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ]);

    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 1, 'skipped' => 0]]);

    $this->assertDatabaseHas('product_reviews', [
        'customer_order_item_id' => $this->orderItem->id,
        'product_id' => $this->product->id,
        'rating' => 5,
    ]);

    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_total_count)->toEqual(1);
    expect($this->product->review_rating_sum)->toEqual(5);
});

it('stores star rating + tags and accumulates review_rating_sum (plan-026)', function () {
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 4,
            'tags' => ['Còn nóng', 'Tươi ngon'],
            'comment' => 'Ngon',
        ]],
    ]);

    $response->assertStatus(201);

    $review = ProductReview::where('customer_order_item_id', $this->orderItem->id)->first();
    expect($review->rating)->toEqual(4);
    expect($review->tags)->toBe(['Còn nóng', 'Tươi ngon']);
    // rating 4 → sentiment up (>=3)
    expect($review->sentiment->value)->toBe('up');

    $this->product->refresh();
    expect($this->product->review_rating_sum)->toEqual(4);
    expect($this->product->review_up_count)->toEqual(1);
});

it('rejects rating outside 1-5', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 6,
        ]],
    ])->assertStatus(422);
});

it('creates batch reviews for multiple items', function () {
    $product2 = Product::factory()->create([
        'organization_id' => $this->organization->id,
        'review_up_count' => 0,
        'review_total_count' => 0,
    ]);
    $sku2 = ProductSku::factory()->create(['product_id' => $product2->id]);
    $item2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $this->order->id,
        'product_sku_id' => $sku2->id,
        'status' => 'served',
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [
            ['order_item_id' => $this->orderItem->id, 'product_id' => $this->product->id, 'rating' => 5],
            ['order_item_id' => $item2->id, 'product_id' => $product2->id, 'rating' => 1],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 2, 'skipped' => 0]]);

    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_total_count)->toEqual(1);

    $product2->refresh();
    expect($product2->review_up_count)->toEqual(0);
    expect($product2->review_total_count)->toEqual(1);
});

it('stores comment when provided', function () {
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
            'comment' => 'Great!',
        ]],
    ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('product_reviews', [
        'customer_order_item_id' => $this->orderItem->id,
        'comment' => 'Great!',
    ]);
});

it('returns reviewable items with already_reviewed = false for unreviewed order', function () {
    $response = $this->getJson("/api/v1/customer/orders/{$this->order->id}/reviewable");

    $response->assertOk()
        ->assertJsonPath('data.order_id', $this->order->id)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.already_reviewed', false);
});

it('marks already-reviewed items in reviewable response', function () {
    ProductReview::factory()->create([
        'customer_order_id' => $this->order->id,
        'customer_order_item_id' => $this->orderItem->id,
        'product_id' => $this->product->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'rating' => 5,
    ]);

    $response = $this->getJson("/api/v1/customer/orders/{$this->order->id}/reviewable");

    $response->assertOk()
        ->assertJsonPath('data.items.0.already_reviewed', true);
});

// =========================================================================
//  Validation
// =========================================================================

it('rejects reviews for open order', function () {
    $order = CustomerOrder::factory()->open()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'order_not_closed');

    expect(ProductReview::count())->toEqual(0);
});

it('rejects reviews for dining order', function () {
    $order = CustomerOrder::factory()->dining()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'order_not_closed');
});

it('returns 404 for non-existent order UUID', function () {
    $fakeId = '00000000-0000-0000-0000-000000000999';

    $this->postJson("/api/v1/customer/orders/{$fakeId}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ])->assertStatus(404)
        ->assertJsonPath('code', 'order_not_found');
});

it('skips order_item_id that does not belong to this order', function () {
    $otherOrder = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
    $otherItem = CustomerOrderItem::factory()->create([
        'customer_order_id' => $otherOrder->id,
        'product_sku_id' => $this->sku->id,
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $otherItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ]);

    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 0, 'skipped' => 1]]);

    $this->product->refresh();
    expect($this->product->review_total_count)->toEqual(0);
});

it('rejects a spoofed product_id that does not match the ordered item (plan-025)', function () {
    // Attacker holds a valid closed-order UUID + a real order_item_id, but
    // supplies the product_id of a DIFFERENT product to manipulate its rating.
    $victim = Product::factory()->create([
        'organization_id' => $this->organization->id,
        'review_up_count' => 0,
        'review_total_count' => 0,
        'review_rating_sum' => 0,
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $victim->id, // spoofed — real ordered product is $this->product
            'rating' => 5,
        ]],
    ]);

    $response->assertStatus(422);

    // No review row written, and neither product's aggregates moved.
    expect(ProductReview::count())->toEqual(0);

    $victim->refresh();
    expect($victim->review_total_count)->toEqual(0);
    expect($victim->review_rating_sum)->toEqual(0);

    $this->product->refresh();
    expect($this->product->review_total_count)->toEqual(0);
});

it('rejects the whole batch when any entry spoofs product_id (plan-025)', function () {
    $victim = Product::factory()->create([
        'organization_id' => $this->organization->id,
        'review_total_count' => 0,
    ]);

    // A second real item on the same order — its entry spoofs $victim's id.
    $product2 = Product::factory()->create(['organization_id' => $this->organization->id]);
    $sku2 = ProductSku::factory()->create(['product_id' => $product2->id]);
    $item2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $this->order->id,
        'product_sku_id' => $sku2->id,
        'status' => 'served',
    ]);

    // First entry is legit, second spoofs — the transaction must roll back both.
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [
            ['order_item_id' => $this->orderItem->id, 'product_id' => $this->product->id, 'rating' => 5],
            ['order_item_id' => $item2->id, 'product_id' => $victim->id, 'rating' => 5],
        ],
    ]);

    $response->assertStatus(422);

    expect(ProductReview::count())->toEqual(0);
    $this->product->refresh();
    expect($this->product->review_total_count)->toEqual(0);
});

// =========================================================================
//  Authorization (public endpoints, UUID as gate)
// =========================================================================

it('allows anonymous GET reviewable on valid closed order', function () {
    $this->getJson("/api/v1/customer/orders/{$this->order->id}/reviewable")
        ->assertOk();
});

it('allows anonymous POST reviews on valid closed order', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ])->assertStatus(201);
});

it('returns 404 for random UUID on GET reviewable', function () {
    $this->getJson('/api/v1/customer/orders/00000000-0000-0000-0000-999999999999/reviewable')
        ->assertStatus(404);
});

it('attaches customer_id when authenticated', function () {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'customer')
        ->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
            'reviews' => [[
                'order_item_id' => $this->orderItem->id,
                'product_id' => $this->product->id,
                'rating' => 1,
            ]],
        ])->assertStatus(201);

    $this->assertDatabaseHas('product_reviews', [
        'customer_order_item_id' => $this->orderItem->id,
        'customer_id' => $customer->id,
    ]);
});

// =========================================================================
//  Idempotency / uniqueness
// =========================================================================

it('skips already-reviewed item and does not change aggregate', function () {
    // First submission
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ])->assertStatus(201);

    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_total_count)->toEqual(1);

    // Second submission (same item)
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 1,
        ]],
    ]);

    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 0, 'skipped' => 1]]);

    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_total_count)->toEqual(1);
    expect(ProductReview::where('customer_order_item_id', $this->orderItem->id)->count())->toEqual(1);
});

it('does not 500 on a concurrent double-submit that loses the unique-index race', function () {
    // Reproduce the exact window a concurrent double-submit hits: a racing
    // request already inserted a review row for THIS order item, but under
    // bookkeeping the pre-check snapshot (scoped to $this->order) cannot see,
    // so submit() proceeds to INSERT and collides with the unique index on
    // customer_order_item_id. Must degrade to an idempotent skip, not a 500.
    $order2 = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    ProductReview::factory()->create([
        'customer_order_id' => $order2->id, // different order → snapshot for $this->order misses it
        'customer_order_item_id' => $this->orderItem->id,
        'product_id' => $this->product->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'rating' => 3,
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ]);

    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 0, 'skipped' => 1]]);

    // Exactly one review row survives (the racing one); aggregate not touched.
    expect(ProductReview::where('customer_order_item_id', $this->orderItem->id)->count())->toEqual(1);
    $this->product->refresh();
    expect($this->product->review_total_count)->toEqual(0);
    expect($this->product->review_up_count)->toEqual(0);
});

it('handles mixed batch: already-reviewed + new', function () {
    // Pre-review item A
    ProductReview::factory()->create([
        'customer_order_id' => $this->order->id,
        'customer_order_item_id' => $this->orderItem->id,
        'product_id' => $this->product->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'rating' => 5,
    ]);
    $this->product->update(['review_up_count' => 1, 'review_total_count' => 1]);

    // New item B
    $product2 = Product::factory()->create([
        'organization_id' => $this->organization->id,
        'review_up_count' => 0,
        'review_total_count' => 0,
    ]);
    $sku2 = ProductSku::factory()->create(['product_id' => $product2->id]);
    $item2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $this->order->id,
        'product_sku_id' => $sku2->id,
        'status' => 'served',
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [
            ['order_item_id' => $this->orderItem->id, 'product_id' => $this->product->id, 'rating' => 5],
            ['order_item_id' => $item2->id, 'product_id' => $product2->id, 'rating' => 5],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 1, 'skipped' => 1]]);

    $product2->refresh();
    expect($product2->review_up_count)->toEqual(1);
    expect($product2->review_total_count)->toEqual(1);

    // Product A aggregate unchanged
    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_total_count)->toEqual(1);
});

it('has unique index on customer_order_item_id', function () {
    ProductReview::factory()->create([
        'customer_order_id' => $this->order->id,
        'customer_order_item_id' => $this->orderItem->id,
        'product_id' => $this->product->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'rating' => 5,
    ]);

    expect(fn () => ProductReview::factory()->create([
        'customer_order_id' => $this->order->id,
        'customer_order_item_id' => $this->orderItem->id,
        'product_id' => $this->product->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'rating' => 1,
    ]))->toThrow(QueryException::class);
});

// =========================================================================
//  Aggregate correctness
// =========================================================================

it('computes recommendPercent correctly', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->organization->id,
        'review_up_count' => 9,
        'review_total_count' => 10,
    ]);

    expect($product->recommendPercent())->toEqual(90);
});

it('returns null recommendPercent when no reviews', function () {
    $product = Product::factory()->create([
        'organization_id' => $this->organization->id,
        'review_up_count' => 0,
        'review_total_count' => 0,
    ]);

    expect($product->recommendPercent())->toBeNull();
});

it('aggregates correctly across multiple orders for same product', function () {
    // Order 1: thumbs up
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ])->assertStatus(201);

    // Order 2: thumbs down (same product, different order)
    $order2 = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
    $item2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order2->id,
        'product_sku_id' => $this->sku->id,
        'status' => 'served',
    ]);

    $this->postJson("/api/v1/customer/orders/{$order2->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $item2->id,
            'product_id' => $this->product->id,
            'rating' => 1,
        ]],
    ])->assertStatus(201);

    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_total_count)->toEqual(2);
    expect($this->product->recommendPercent())->toEqual(50);
});

it('handles concurrent reviews without lost updates', function () {
    // Simulate two separate orders reviewing same product
    $order2 = CustomerOrder::factory()->closed()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);
    $item2 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order2->id,
        'product_sku_id' => $this->sku->id,
        'status' => 'served',
    ]);

    // Sequential calls (lockForUpdate ensures serial execution under real concurrency)
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ])->assertStatus(201);

    $this->postJson("/api/v1/customer/orders/{$order2->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $item2->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ])->assertStatus(201);

    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(2);
    expect($this->product->review_total_count)->toEqual(2);
});

// =========================================================================
//  Menu integration
// =========================================================================

it('includes rating and reviewCount in customer menu for product with reviews', function () {
    $this->product->update(['review_up_count' => 9, 'review_total_count' => 10]);

    $zone = Zone::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
    ]);
    Table::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'zone_id' => $zone->id,
        'qr_token' => 'review-test-token',
        'is_active' => true,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create(['name' => 'Main']);
    $menu->menuSections()->attach($section);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'menu_section_id' => $section->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 0,
    ]);

    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 500,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/customer/tables/review-test-token/menu');

    $response->assertOk();

    $items = collect($response->json('data.categories'))->flatMap(fn ($c) => $c['items']);
    $item = $items->first();

    expect($item)->not->toBeNull();
    expect($item['rating'])->toEqual(90);
    expect($item['reviewCount'])->toEqual(10);
});

it('returns null rating for product with no reviews in customer menu', function () {
    $zone = Zone::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
    ]);
    Table::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'zone_id' => $zone->id,
        'qr_token' => 'review-test-token-2',
        'is_active' => true,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create(['name' => 'Main']);
    $menu->menuSections()->attach($section);

    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'menu_section_id' => $section->id,
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 0,
    ]);

    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $this->sku->id,
        'selling_price' => 500,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/customer/tables/review-test-token-2/menu');

    $response->assertOk();

    $items = collect($response->json('data.categories'))->flatMap(fn ($c) => $c['items']);
    $item = $items->first();

    expect($item)->not->toBeNull();
    expect($item['rating'])->toBeNull();
    expect($item['reviewCount'])->toEqual(0);
});

// =========================================================================
//  Edge cases
// =========================================================================

it('skips voided item in review submission', function () {
    $voidedItem = CustomerOrderItem::factory()->voided()->create([
        'customer_order_id' => $this->order->id,
        'product_sku_id' => $this->sku->id,
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $voidedItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ]);

    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 0, 'skipped' => 1]]);

    $this->product->refresh();
    expect($this->product->review_total_count)->toEqual(0);
});

it('excludes voided items from reviewable list', function () {
    CustomerOrderItem::factory()->voided()->create([
        'customer_order_id' => $this->order->id,
        'product_sku_id' => $this->sku->id,
    ]);

    $response = $this->getJson("/api/v1/customer/orders/{$this->order->id}/reviewable");

    $response->assertOk();
    // Only the non-voided item from beforeEach
    $response->assertJsonCount(1, 'data.items');
});

it('does not crash when product is soft-deleted', function () {
    $this->product->delete();

    $zone = Zone::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
    ]);
    Table::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'zone_id' => $zone->id,
        'qr_token' => 'deleted-product-test',
        'is_active' => true,
    ]);

    $menu = Menu::factory()->create([
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $section = MenuSection::factory()->create(['name' => 'Main']);
    $menu->menuSections()->attach($section);

    // Menu product won't appear since product is soft-deleted
    $response = $this->getJson('/api/v1/customer/tables/deleted-product-test/menu');
    $response->assertOk();
});

it('handles mixed batch: valid + already-reviewed + voided', function () {
    // Already-reviewed item
    ProductReview::factory()->create([
        'customer_order_id' => $this->order->id,
        'customer_order_item_id' => $this->orderItem->id,
        'product_id' => $this->product->id,
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'rating' => 5,
    ]);
    $this->product->update(['review_up_count' => 1, 'review_total_count' => 1]);

    // Voided item
    $voidedItem = CustomerOrderItem::factory()->voided()->create([
        'customer_order_id' => $this->order->id,
        'product_sku_id' => $this->sku->id,
    ]);

    // New valid item
    $product3 = Product::factory()->create([
        'organization_id' => $this->organization->id,
        'review_up_count' => 0,
        'review_total_count' => 0,
    ]);
    $sku3 = ProductSku::factory()->create(['product_id' => $product3->id]);
    $item3 = CustomerOrderItem::factory()->create([
        'customer_order_id' => $this->order->id,
        'product_sku_id' => $sku3->id,
        'status' => 'served',
    ]);

    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [
            ['order_item_id' => $this->orderItem->id, 'product_id' => $this->product->id, 'rating' => 1],
            ['order_item_id' => $voidedItem->id, 'product_id' => $this->product->id, 'rating' => 5],
            ['order_item_id' => $item3->id, 'product_id' => $product3->id, 'rating' => 5],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 1, 'skipped' => 2]]);

    $product3->refresh();
    expect($product3->review_up_count)->toEqual(1);
    expect($product3->review_total_count)->toEqual(1);

    // Original product unchanged
    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_total_count)->toEqual(1);
});

// =========================================================================
//  Multi-tenant isolation (write path) — plan-audit gap
// =========================================================================

it('does not let a foreign-org order item be reviewed through this order', function () {
    // A separate tenant (org B) with its own closed order + item. The attacker
    // holds org A's order UUID but supplies org B's order_item_id + product.
    $orgB = Organization::factory()->create();
    $brandB = Brand::factory()->create([
        'console_organization_id' => $orgB->console_organization_id,
    ]);
    $branchB = Branch::factory()->create([
        'console_organization_id' => $orgB->console_organization_id,
        'console_brand_id' => $brandB->console_brand_id,
    ]);
    $productB = Product::factory()->create([
        'organization_id' => $orgB->id,
        'review_up_count' => 0,
        'review_total_count' => 0,
        'review_rating_sum' => 0,
    ]);
    $skuB = ProductSku::factory()->create(['product_id' => $productB->id]);
    $orderB = CustomerOrder::factory()->closed()->create([
        'organization_id' => $orgB->id,
        'brand_id' => $brandB->id,
        'branch_id' => $branchB->id,
    ]);
    $itemB = CustomerOrderItem::factory()->create([
        'customer_order_id' => $orderB->id,
        'product_sku_id' => $skuB->id,
        'status' => 'served',
    ]);

    // POST against org A's order, but the item belongs to org B's order.
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $itemB->id,
            'product_id' => $productB->id,
            'rating' => 5,
        ]],
    ]);

    // The item is not part of THIS order → silently skipped, nothing written.
    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 0, 'skipped' => 1]]);

    expect(ProductReview::count())->toEqual(0);

    // The foreign tenant's product aggregate must be untouched.
    $productB->refresh();
    expect($productB->review_total_count)->toEqual(0);
    expect($productB->review_up_count)->toEqual(0);
    expect($productB->review_rating_sum)->toEqual(0);
});

it('stamps the review row with the order tenancy, not the product tenancy', function () {
    // Product belongs to the same org here, but the org/brand/branch on the
    // ProductReview row must be sourced from the ORDER (authoritative tenancy
    // for the review), so a reviewer can never write a row scoped to a brand/
    // branch they did not transact with.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ])->assertStatus(201);

    $this->assertDatabaseHas('product_reviews', [
        'customer_order_item_id' => $this->orderItem->id,
        'organization_id' => $this->order->organization_id,
        'brand_id' => $this->order->brand_id,
        'branch_id' => $this->order->branch_id,
    ]);
});

// =========================================================================
//  In-batch idempotency (same item twice in one request)
// =========================================================================

it('deduplicates the same order item repeated within a single batch', function () {
    // Two entries for the SAME item in one request — the second must be skipped
    // by the in-batch bookkeeping (never a unique-index 500), aggregate moves
    // exactly once.
    $response = $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [
            ['order_item_id' => $this->orderItem->id, 'product_id' => $this->product->id, 'rating' => 5],
            ['order_item_id' => $this->orderItem->id, 'product_id' => $this->product->id, 'rating' => 1],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJson(['data' => ['created' => 1, 'skipped' => 1]]);

    expect(ProductReview::where('customer_order_item_id', $this->orderItem->id)->count())->toEqual(1);

    // Only the first entry (rating 5 → up) counted.
    $this->product->refresh();
    expect($this->product->review_total_count)->toEqual(1);
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_rating_sum)->toEqual(5);
});

// =========================================================================
//  Sentiment derivation boundary (rating >= 3 => up)
// =========================================================================

it('derives sentiment up at the exact rating=3 boundary', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 3,
        ]],
    ])->assertStatus(201);

    $review = ProductReview::where('customer_order_item_id', $this->orderItem->id)->first();
    expect($review->sentiment->value)->toBe('up');

    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_total_count)->toEqual(1);
    expect($this->product->review_rating_sum)->toEqual(3);
});

it('derives sentiment down just below the boundary at rating=2', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 2,
        ]],
    ])->assertStatus(201);

    $review = ProductReview::where('customer_order_item_id', $this->orderItem->id)->first();
    expect($review->sentiment->value)->toBe('down');

    $this->product->refresh();
    expect($this->product->review_up_count)->toEqual(0);
    expect($this->product->review_total_count)->toEqual(1);
    expect($this->product->review_rating_sum)->toEqual(2);
});

// =========================================================================
//  recommendPercent rounding boundary (money-style half-rounding)
// =========================================================================

it('rounds recommendPercent at the .5 boundary away from zero', function () {
    // 1/8 = 12.5% → PHP round() (half away from zero) → 13, never 12.
    $product = Product::factory()->create([
        'organization_id' => $this->organization->id,
        'review_up_count' => 1,
        'review_total_count' => 8,
    ]);

    expect($product->recommendPercent())->toEqual(13);
});

// =========================================================================
//  Aggregate rollback on order force-delete (plan-025 logic-risk)
// =========================================================================

it('rolls back product aggregates when a reviewed order is force-deleted', function () {
    // Submit a review so the product aggregate is non-zero.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 5,
        ]],
    ])->assertStatus(201);

    $this->product->refresh();
    expect($this->product->review_total_count)->toEqual(1);
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_rating_sum)->toEqual(5);

    // Force-deleting the order triggers the DB ON DELETE CASCADE on
    // product_reviews. Aggregates must roll back to zero, not drift.
    $this->order->forceDelete();

    $this->assertDatabaseMissing('product_reviews', [
        'customer_order_item_id' => $this->orderItem->id,
    ]);

    $this->product->refresh();
    expect($this->product->review_total_count)->toEqual(0);
    expect($this->product->review_up_count)->toEqual(0);
    expect($this->product->review_rating_sum)->toEqual(0);
});

it('leaves product aggregates untouched on a soft-delete of the order', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/reviews", [
        'reviews' => [[
            'order_item_id' => $this->orderItem->id,
            'product_id' => $this->product->id,
            'rating' => 4,
        ]],
    ])->assertStatus(201);

    // Plain (soft) delete keeps the row + its reviews; aggregate is unchanged.
    $this->order->delete();

    $this->assertDatabaseHas('product_reviews', [
        'customer_order_item_id' => $this->orderItem->id,
    ]);

    $this->product->refresh();
    expect($this->product->review_total_count)->toEqual(1);
    expect($this->product->review_up_count)->toEqual(1);
    expect($this->product->review_rating_sum)->toEqual(4);
});
