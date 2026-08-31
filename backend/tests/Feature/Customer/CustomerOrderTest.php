<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Zone;

beforeEach(function () {
    $this->brand = Brand::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => '00000000-0000-0000-0000-000000000001',
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->zone = Zone::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'order-test-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    $this->sku = ProductSku::factory()->create();
});

// =========================================================================
//  Happy path
// =========================================================================

it('creates an order with items and updates table status', function () {
    $response = $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 2],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'code', 'status', 'items', 'subtotal', 'total']]);

    $this->table->refresh();
    expect($this->table->current_order_id)->not->toBeNull();
    expect($this->table->status->value)->toBe('occupied');

    $this->assertDatabaseHas('customer_orders', [
        'status' => 'open',
        'branch_id' => $this->branch->id,
    ]);
});

it('returns the current order for a table', function () {
    // Create an order first
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    $response = $this->getJson('/api/v1/customer/tables/order-test-token/order');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['order' => ['id', 'code', 'items']]]);
});

it('exposes options and note on each order item for the split-by-item UI', function () {
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    $this->getJson('/api/v1/customer/tables/order-test-token/order')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'order' => [
                    'items' => [
                        ['id', 'name', 'qty', 'unit_price', 'subtotal', 'note', 'options'],
                    ],
                ],
            ],
        ]);
});

it('returns an order by ID', function () {
    $createResponse = $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ]);

    $orderId = $createResponse->json('data.id');

    $response = $this->getJson("/api/v1/customer/orders/{$orderId}");

    $response->assertOk()
        ->assertJsonStructure(['data' => ['id', 'code', 'items']]);
});

// =========================================================================
//  Validation
// =========================================================================

it('returns 422 for empty items array', function () {
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [],
    ])->assertStatus(422)->assertJsonValidationErrors('items');
});

it('returns 422 for non-existent product_sku_id', function () {
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => '00000000-0000-0000-0000-000000000099', 'quantity' => 1],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_sku_id');
});

it('returns 422 for quantity zero', function () {
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 0],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
});

// =========================================================================
//  Edge cases
// =========================================================================

it('returns null order when table has no current order', function () {
    $this->getJson('/api/v1/customer/tables/order-test-token/order')
        ->assertOk()
        ->assertJson(['data' => ['order' => null]]);
});

it('sets customer_id when authenticated', function () {
    $account = Customer::factory()->selfRegistered()->create();
    $token = $account->createToken('test')->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_id)->toBe($account->id);
});

it('leaves customer_id null for guest orders', function () {
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->customer_id)->toBeNull();
});

// =========================================================================
//  Error handling
// =========================================================================

it('returns 404 for non-existent qr_token on order create', function () {
    $this->postJson('/api/v1/customer/tables/nonexistent/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertNotFound();
});

it('returns 404 for non-existent order ID', function () {
    $this->getJson('/api/v1/customer/orders/00000000-0000-0000-0000-000000000099')
        ->assertNotFound();
});

it('adds items to existing order when table already has an active order', function () {
    // Create first order
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    // Second request — same sku, same price, no note → merges with the
    // first pending line per BR-OI06 rather than creating a duplicate row.
    // Joining the table's existing active order returns 200 (shared session),
    // not 201 — only the first request creates a new order.
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 2],
        ],
    ])->assertStatus(200);

    $order = CustomerOrder::first();

    // Still only one order, and the two requests collapsed into one line.
    expect(CustomerOrder::count())->toBe(1);
    expect($order->items)->toHaveCount(1);
    expect((float) $order->items->first()->quantity)->toBe(3.0);
});

it('creates a fresh order when the table points at a hard-deleted order (dangling current_order_id)', function () {
    // Simulate a table whose current_order_id references an order that was
    // hard-deleted out from under it. Previously CustomerQrOrderService::
    // createOrder() did findOrFail($table->current_order_id) here, throwing
    // ModelNotFound → 404 on EVERY order attempt, permanently bricking the
    // table. The fix uses find() and treats a missing order like a closed one.
    $this->table->forceFill([
        'current_order_id' => '00000000-0000-0000-0000-0000deadbeef',
        'status' => 'occupied',
    ])->save();

    $response = $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ]);

    // Must self-heal: a brand-new order is created (201), not a 404.
    $response->assertStatus(201)
        ->assertJsonStructure(['data' => ['id', 'code', 'status', 'items']]);

    $this->table->refresh();
    expect($this->table->current_order_id)->not->toBeNull();
    expect($this->table->current_order_id)->not->toBe('00000000-0000-0000-0000-0000deadbeef');
    expect(CustomerOrder::count())->toBe(1);
});

it('keeps prior items visible when customer adds a different SKU on the same table', function () {
    $sku2 = ProductSku::factory()->create();

    // First order — món A
    $first = $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertStatus(201)->json('data');

    // Second order — món B (different SKU). BR-OI06 cannot merge across SKUs,
    // so the response must include both the prior pending item AND the new one.
    // Joining the table's existing active order returns 200 (shared session).
    $second = $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $sku2->id, 'quantity' => 1],
        ],
    ])->assertStatus(200)->json('data');

    expect(CustomerOrder::count())->toBe(1);
    expect($second['id'])->toBe($first['id']);
    expect($second['items'])->toHaveCount(2);
    expect(collect($second['items'])->pluck('id')->all())->toContain($first['items'][0]['id']);
});

// =========================================================================
//  Side effects
// =========================================================================

it('sets created_by_id to null for customer-created orders', function () {
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    $order = CustomerOrder::latest()->first();
    expect($order->created_by_id)->toBeNull();
});

it('sets table current_order_id and status to occupied', function () {
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $this->sku->id, 'quantity' => 1],
        ],
    ])->assertStatus(201);

    $this->table->refresh();
    expect($this->table->current_order_id)->not->toBeNull();
    expect($this->table->status->value)->toBe('occupied');
});

// =========================================================================
//  Plan-019 — coupon_code applied inside the order create transaction
// =========================================================================

it('applies coupon_code atomically during customer order create', function () {
    $sku = ProductSku::factory()->create(['selling_price' => 100000]);

    $coupon = Coupon::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'code' => 'WELCOMEAPPLY',
        'discount_type' => 'fixed',
        'discount_value' => 15000,
        'min_order_subtotal' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'status' => 'draft',
    ]);

    $response = $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $sku->id, 'quantity' => 1],
        ],
        'coupon_code' => 'WELCOMEAPPLY',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.discount_amount', 15000)
        ->assertJsonPath('data.coupon_code_snapshot', 'WELCOMEAPPLY');

    expect($coupon->fresh()->times_used)->toBe(1);
});

it('rolls back the whole order when an invalid coupon_code is supplied', function () {
    $sku = ProductSku::factory()->create();

    $response = $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $sku->id, 'quantity' => 1],
        ],
        'coupon_code' => 'NOSUCHCODE',
    ]);

    $response->assertStatus(404)
        ->assertJsonPath('error_code', 'coupon_not_found');

    // Whole transaction rolled back — no order, no items, table untouched.
    expect(CustomerOrder::count())->toBe(0);
    $this->table->refresh();
    expect($this->table->current_order_id)->toBeNull();
});

/**
 * #1688 — the same rollback, measured at the FAR END of the transaction body.
 *
 * The test above stops at `customer_orders`, which the order-create path itself
 * would also clean up. The table SESSION is opened between the create and the
 * coupon apply, by a plain `TableSession::create()` with no compensating
 * delete of its own: nothing but the surrounding transaction takes it back. So
 * a leftover open session on a table with no order — the state that makes every
 * later scan of that QR code append to a ghost — is the signal that the
 * transaction boundary was lost, and it is invisible to an order-only
 * assertion.
 */
it('rolls back the table session too when the coupon fails', function () {
    $sku = ProductSku::factory()->create();

    expect(TableSession::where('table_id', $this->table->id)->count())->toBe(0);

    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [
            ['product_sku_id' => $sku->id, 'quantity' => 1],
        ],
        'coupon_code' => 'NOSUCHCODE',
    ])->assertStatus(404);

    // Asserted FIRST on purpose: this is the assertion that only the
    // transaction can satisfy, so it is the one that must be seen to fail when
    // the boundary is taken away.
    expect(TableSession::where('table_id', $this->table->id)->count())->toBe(0);
    expect(CustomerOrder::count())->toBe(0);
    expect(CustomerOrderItem::count())->toBe(0);
});

// =========================================================================
//  #514 — charge the exact menu line the customer saw
// =========================================================================

it('charges the menu_product_sku_id price the customer saw, not another line for the same SKU', function () {
    // A product_sku that sits in TWO menu lines at different prices — the exact
    // seed anomaly behind #514 (customer saw 1 000đ, server rang up 2 000đ).
    $menu = Menu::factory()->create([
        'organization_id' => '00000000-0000-0000-0000-000000000001',
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
    // Two menu_products carry the same product_sku (a (menu_product_id,
    // product_sku_id) pair is unique, so the divergent prices live on separate
    // menu_products — exactly how the seed data ends up).
    $shownLine = MenuProductSku::factory()->create([
        'menu_product_id' => MenuProduct::factory()->create([
            'menu_id' => $menu->id,
            'product_id' => $this->sku->product_id,
            'is_active' => true,
        ])->id,
        'product_sku_id' => $this->sku->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);
    // A second line for the SAME sku the arbitrary fallback might otherwise pick.
    MenuProductSku::factory()->create([
        'menu_product_id' => MenuProduct::factory()->create([
            'menu_id' => $menu->id,
            'product_id' => $this->sku->product_id,
            'is_active' => true,
        ])->id,
        'product_sku_id' => $this->sku->id,
        'is_active' => true,
        'selling_price' => 2000,
    ]);

    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'menu_product_sku_id' => $shownLine->id,
            'quantity' => 1,
        ]],
    ])->assertStatus(201);

    // The line is priced from the referenced menu line (1 000), not the 2 000 one.
    $item = CustomerOrderItem::firstOrFail();
    expect((float) $item->unit_price)->toBe(1000.0)
        ->and((float) $item->subtotal)->toBe(1000.0);
});

it('rejects a menu_product_sku_id that does not exist', function () {
    $this->postJson('/api/v1/customer/tables/order-test-token/orders', [
        'items' => [[
            'product_sku_id' => $this->sku->id,
            'menu_product_sku_id' => '00000000-0000-0000-0000-0000000000aa',
            'quantity' => 1,
        ]],
    ])->assertStatus(422)->assertJsonValidationErrors('items.0.menu_product_sku_id');
});
