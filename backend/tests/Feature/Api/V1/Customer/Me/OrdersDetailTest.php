<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\File;
use App\Models\OrderItemTopping;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\ProductSku;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create();
    $this->branch = Branch::factory()->create();

    $this->customer = Customer::factory()->selfRegistered()->create([
        'password' => 'password',
    ]);
    $this->token = $this->customer->createToken('test')->plainTextToken;
});

it('returns order detail for own order', function () {
    $order = CustomerOrder::factory()->closed()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);

    $response = $this->getJson("/api/v1/customer/me/orders/{$order->id}", [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()
        ->assertJsonPath('data.id', $order->id)
        ->assertJsonPath('data.order_code', $order->order_code)
        ->assertJsonStructure([
            'data' => ['id', 'order_code', 'order_type', 'status', 'subtotal', 'total_amount', 'items', 'payments', 'branch'],
        ]);
});

it('returns 404 for another customers order', function () {
    $otherCustomer = Customer::factory()->selfRegistered()->create(['password' => 'password']);
    $order = CustomerOrder::factory()->closed()->create([
        'customer_id' => $otherCustomer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);

    $response = $this->getJson("/api/v1/customer/me/orders/{$order->id}", [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertNotFound();
});

it('returns 404 for non-existent order', function () {
    $response = $this->getJson('/api/v1/customer/me/orders/00000000-0000-0000-0000-000000000000', [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertNotFound();
});

it('eager-loads branch, items(sku.product), payments and tables under strict mode', function () {
    // N+1 regression guard for the detail endpoint: with lazy loading disabled,
    // rendering CustomerOrderDetailResource (which reads branch, each item's
    // productSku->product, payment->paymentMethod and tables) must not trigger
    // a single lazy load. Exercises the realistic case: an order that actually
    // has line items and payments.
    $order = CustomerOrder::factory()->closed()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);
    CustomerOrderItem::factory()->count(3)->create(['customer_order_id' => $order->id]);
    OrderPayment::factory()->count(2)->create(['customer_order_id' => $order->id]);

    Model::preventLazyLoading(true);

    try {
        $response = $this->getJson("/api/v1/customer/me/orders/{$order->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);
    } finally {
        Model::preventLazyLoading(false);
    }

    $response->assertOk()
        ->assertJsonCount(3, 'data.items')
        ->assertJsonCount(2, 'data.payments');
});

it('serves the fields the shared order-detail screen renders', function () {
    // The account detail screen renders the same components as the guest one
    // (`/orders/{id}`), whose payload is CustomerOrderController::formatOrder().
    // This resource used to carry almost none of that contract — no `code`, no
    // payment state, and line items with no photo, variant or toppings — so the
    // two screens could not share a single component. Lock the union.
    $order = CustomerOrder::factory()->closed()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
        'total_amount' => 1000,
        'paid_amount' => 1000,
    ]);
    CustomerOrderItem::factory()->create(['customer_order_id' => $order->id]);

    $response = $this->getJson("/api/v1/customer/me/orders/{$order->id}", [
        'Authorization' => "Bearer {$this->token}",
    ]);

    $response->assertOk()
        ->assertJsonPath('data.code', $order->order_code)
        // Both spellings survive: `order_code` / `total_amount` / `quantity` are
        // what clients already read, the aliases are what the shared components
        // read. Dropping either half breaks one of the two screens.
        ->assertJsonPath('data.order_code', $order->order_code)
        ->assertJsonPath('data.total', 1000)
        ->assertJsonPath('data.total_amount', 1000)
        ->assertJsonPath('data.paid', 1000)
        ->assertJsonPath('data.remaining', 0)
        ->assertJsonPath('data.is_fully_paid', true)
        ->assertJsonStructure([
            'data' => [
                'placed_at', 'payment_count', 'payment_due_at', 'seconds_until_due',
                'is_payment_overdue', 'coupon_code_snapshot',
                'items' => [[
                    'id', 'name', 'product_name', 'image_url', 'variant',
                    'qty', 'quantity', 'unit_price', 'subtotal', 'note',
                    'options', 'status', 'tax_rate',
                ]],
            ],
        ]);
});

it('reports an unpaid order as unpaid so the screen can offer a way to pay', function () {
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);

    $this->getJson("/api/v1/customer/me/orders/{$order->id}", [
        'Authorization' => "Bearer {$this->token}",
    ])
        ->assertOk()
        ->assertJsonPath('data.is_fully_paid', false)
        ->assertJsonPath('data.remaining', 1000)
        ->assertJsonPath('data.payment_count', 0);
});

it('resolves each line item photo and its chosen toppings', function () {
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

    $toppingSku = ProductSku::factory()->create();
    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $item->id,
        'product_sku_id' => $toppingSku->id,
        'quantity' => 2,
        'unit_price' => 100,
    ]);

    Model::preventLazyLoading(true);

    try {
        $response = $this->getJson("/api/v1/customer/me/orders/{$order->id}", [
            'Authorization' => "Bearer {$this->token}",
        ]);
    } finally {
        Model::preventLazyLoading(false);
    }

    $response->assertOk()
        ->assertJsonCount(1, 'data.items.0.options')
        ->assertJsonPath('data.items.0.options.0.quantity', 2)
        ->assertJsonPath('data.items.0.options.0.name', $toppingSku->product->name);

    expect($response->json('data.items.0.image_url'))->not->toBeNull();
});

it('returns 401 without token', function () {
    $order = CustomerOrder::factory()->closed()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->organization->id,
    ]);

    $this->getJson("/api/v1/customer/me/orders/{$order->id}")->assertUnauthorized();
});
