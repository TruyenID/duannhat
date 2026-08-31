<?php

/**
 * #130 P2 — Customer/CustomerOrderHistoryController coverage
 *
 * Authenticated endpoints (auth:customer):
 *   GET /api/v1/customer/me/orders          — list of customer's orders (cursor paginated)
 *   GET /api/v1/customer/me/orders/{id}     — detail of a specific order
 */

use App\Models\Branch;
use App\Models\BranchReview;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ProductReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    $this->customer = Customer::factory()->selfRegistered()->create();
});

// =============================================================================
// /me/orders (index)
// =============================================================================

it('returns the authenticated customer\'s orders', function () {
    CustomerOrder::factory()->count(3)->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/customer/me/orders')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('does not include another customer\'s orders', function () {
    CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $other = Customer::factory()->selfRegistered()->create();
    CustomerOrder::factory()->create([
        'customer_id' => $other->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;
    $response = $this->withToken($token)->getJson('/api/v1/customer/me/orders');

    expect($response->json('data'))->toHaveCount(1);
});

it('filters orders by status', function () {
    CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => 'closed',
    ]);
    CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => 'open',
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;
    $response = $this->withToken($token)->getJson('/api/v1/customer/me/orders?status=closed');

    expect($response->json('data'))->toHaveCount(1);
});

it('returns 401 on /me/orders without auth', function () {
    $this->getJson('/api/v1/customer/me/orders')->assertUnauthorized();
});

// =============================================================================
// /me/orders/{id} (show)
// =============================================================================

it('returns order detail when the order belongs to the customer', function () {
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;

    $response = $this->withToken($token)
        ->getJson("/api/v1/customer/me/orders/{$order->id}")
        ->assertOk()
        ->assertJsonStructure(['data' => ['id']]);

    expect($response->json('data.id'))->toBe($order->id);
});

it('returns 404 when the order belongs to another customer', function () {
    $other = Customer::factory()->selfRegistered()->create();
    $foreignOrder = CustomerOrder::factory()->create([
        'customer_id' => $other->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson("/api/v1/customer/me/orders/{$foreignOrder->id}")
        ->assertNotFound();
});

it('returns 404 for non-existent order id', function () {
    $token = $this->customer->createToken('test')->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/customer/me/orders/'.Str::uuid())
        ->assertNotFound();
});

it('returns 401 on /me/orders/{id} without auth', function () {
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $this->getJson("/api/v1/customer/me/orders/{$order->id}")->assertUnauthorized();
});

// =============================================================================
// POST /api/v1/customer/orders/batch (guest order-history list, public)
//
// The guest /orders page reads expiry off this endpoint. Its summary payload
// MUST carry is_payment_overdue so the client's isServerConfirmedExpired() can
// flip an expired order's action from 「Thanh toán」to 「Đặt lại」— the cosmetic
// countdown badge alone (payment_due_at) is not enough to swap the button.
// =============================================================================

it('exposes is_payment_overdue=true for an overdue order in the batch payload', function () {
    $order = CustomerOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'payment_due_at' => now()->subMinutes(3),
    ]);

    $payload = $this->postJson('/api/v1/customer/orders/batch', ['ids' => [$order->id]])
        ->assertOk()
        ->json('data.0');

    expect($payload['is_payment_overdue'])->toBeTrue()
        ->and($payload['seconds_until_due'])->toBe(0);
});

it('exposes is_payment_overdue=false and a live countdown for a not-yet-due order', function () {
    $order = CustomerOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'payment_due_at' => now()->addMinutes(10),
    ]);

    $payload = $this->postJson('/api/v1/customer/orders/batch', ['ids' => [$order->id]])
        ->assertOk()
        ->json('data.0');

    expect($payload['is_payment_overdue'])->toBeFalse()
        ->and($payload['seconds_until_due'])->toBeGreaterThan(0);
});

it('reports is_payment_overdue=false when no payment_due_at is set', function () {
    $order = CustomerOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'payment_due_at' => null,
    ]);

    $payload = $this->postJson('/api/v1/customer/orders/batch', ['ids' => [$order->id]])
        ->assertOk()
        ->json('data.0');

    expect($payload['is_payment_overdue'])->toBeFalse()
        ->and($payload['seconds_until_due'])->toBeNull();
});

// =============================================================================
// #1758 — `is_reviewed` trên payload lịch sử đơn hàng
//
// Card lịch sử đổi nút 「Viết đánh giá」thành trạng thái 「Đã đánh giá」dựa
// hoàn toàn vào cờ này. Nó phải có mặt ở CẢ HAI đường đọc — khách đã đăng nhập
// (/me/orders) và khách vãng lai (/orders/batch) — vì hai màn hình dùng chung
// một card; thiếu một bên là một nửa số khách vẫn bấm vào trang review rỗng.
//
// "Đã đánh giá" tính theo ĐƠN: một ProductReview HOẶC một BranchReview là đủ.
// =============================================================================

it('reports is_reviewed=true on /me/orders when the order has a branch review', function () {
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
    BranchReview::factory()->create([
        'customer_order_id' => $order->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;
    $payload = $this->withToken($token)
        ->getJson('/api/v1/customer/me/orders')
        ->assertOk()
        ->json('data.0');

    expect($payload['is_reviewed'])->toBeTrue();
});

it('reports is_reviewed=true on /me/orders when only a dish was reviewed', function () {
    $order = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
    $item = CustomerOrderItem::factory()->create(['customer_order_id' => $order->id]);
    ProductReview::factory()->create([
        'customer_order_id' => $order->id,
        'customer_order_item_id' => $item->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;
    $payload = $this->withToken($token)
        ->getJson('/api/v1/customer/me/orders')
        ->assertOk()
        ->json('data.0');

    expect($payload['is_reviewed'])->toBeTrue();
});

it('reports is_reviewed=false on /me/orders for an order nobody reviewed', function () {
    CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;
    $payload = $this->withToken($token)
        ->getJson('/api/v1/customer/me/orders')
        ->assertOk()
        ->json('data.0');

    expect($payload['is_reviewed'])->toBeFalse();
});

it('does not leak another order\'s review into is_reviewed', function () {
    // Cùng chi nhánh, cùng khách — nếu cờ nhỡ hỏi "chi nhánh này có review nào
    // không" thay vì "ĐƠN NÀY có review nào không" thì cả hai đơn cùng sáng.
    $reviewed = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'created_at' => now()->subHour(),
    ]);
    $untouched = CustomerOrder::factory()->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'created_at' => now(),
    ]);
    BranchReview::factory()->create([
        'customer_order_id' => $reviewed->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;
    $byId = collect($this->withToken($token)->getJson('/api/v1/customer/me/orders')->json('data'))
        ->keyBy('id');

    expect($byId[$reviewed->id]['is_reviewed'])->toBeTrue()
        ->and($byId[$untouched->id]['is_reviewed'])->toBeFalse();
});

it('exposes is_reviewed in the guest batch payload too', function () {
    $reviewed = CustomerOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
    $untouched = CustomerOrder::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);
    BranchReview::factory()->create([
        'customer_order_id' => $reviewed->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $byId = collect(
        $this->postJson('/api/v1/customer/orders/batch', ['ids' => [$reviewed->id, $untouched->id]])
            ->assertOk()
            ->json('data')
    )->keyBy('id');

    expect($byId[$reviewed->id]['is_reviewed'])->toBeTrue()
        ->and($byId[$untouched->id]['is_reviewed'])->toBeFalse();
});

it('resolves is_reviewed for a whole page without a query per order', function () {
    // Cờ này nằm trên đường đọc nóng nhất của khách (20 đơn/trang). Nếu resource
    // tự hỏi từng đơn thì là 40 câu truy vấn thêm — test đếm để giữ nó là hai
    // subquery EXISTS đi kèm câu list.
    CustomerOrder::factory()->count(5)->create([
        'customer_id' => $this->customer->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
    ]);

    $token = $this->customer->createToken('test')->plainTextToken;

    DB::enableQueryLog();
    $this->withToken($token)->getJson('/api/v1/customer/me/orders')->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $reviewQueries = collect($queries)->filter(
        fn (array $q) => str_contains($q['query'], 'product_reviews') || str_contains($q['query'], 'branch_reviews')
    );

    // Đúng MỘT câu — chính câu list, mang hai EXISTS trong mệnh đề select. Con
    // số này không được nhân theo số đơn: 5 đơn hay 20 đơn vẫn là 1.
    expect($reviewQueries)->toHaveCount(1);
    expect($reviewQueries->first()['query'])->toContain('customer_orders');
});
