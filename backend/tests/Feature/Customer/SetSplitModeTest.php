<?php

/**
 * Plan-039 — `POST /api/v1/customer/orders/{id}/split-mode` contract.
 *
 * The endpoint is what propagates the customer's chosen split-bill mode
 * from customer-web to the kiosk via `customer_orders.split_mode`. PR
 * #377 wired the route + KioskOrderResource exposure but the controller
 * method body was never landed; this plan adds the body + the counter-pay
 * `custom` rejection (ADR-1) + status / lock guards.
 *
 * Coverage matches the scenarios in plan-039 TESTS (archived, removed from tree #2188 — git history) section
 * "Backend (Pest)".
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
});

/**
 * Counter-pay = no Stripe intent minted yet. The controller infers this
 * from `stripe_payment_intent_id IS NULL`. Pay-online orders have an
 * intent stored before the customer reaches the payment-view's "split"
 * tab.
 */
function makeOrder(string $orgId, string $brandId, string $branchId, array $overrides = []): CustomerOrder
{
    return CustomerOrder::factory()->create(array_merge([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'status' => 'pending',
        'paid_amount' => 0,
        'split_mode' => null,
        'stripe_payment_intent_id' => null, // counter-pay by default
    ], $overrides));
}

it('accepts even on counter-pay pending order', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
    ])
        ->assertOk()
        ->assertJsonPath('data.split_mode', 'even')
        ->assertJsonPath('data.split_mode_locked', false);

    expect($order->fresh()->split_mode)->toBe('even');
});

it('accepts by_items on counter-pay pending order', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'by_items',
    ])
        ->assertOk()
        ->assertJsonPath('data.split_mode', 'by_items');

    expect($order->fresh()->split_mode)->toBe('by_items');
});

it('rejects by_amount split-mode on counter-pay (ADR-1)', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'by_amount',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'SPLIT_MODE_INVALID_FOR_COUNTER');

    expect($order->fresh()->split_mode)->toBeNull();
});

it('accepts by_amount on pay-online order (regression PR #377)', function () {
    // Pay-online identified by presence of stripe_payment_intent_id.
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        'stripe_payment_intent_id' => 'pi_test_abc123',
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'by_amount',
    ])
        ->assertOk()
        ->assertJsonPath('data.split_mode', 'by_amount');

    expect($order->fresh()->split_mode)->toBe('by_amount');
});

it('locks with 409 when paid_amount > 0', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        'paid_amount' => 50000,
        'split_mode' => 'even',
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'by_items',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'SPLIT_MODE_LOCKED');

    // mode unchanged
    expect($order->fresh()->split_mode)->toBe('even');
});

it('allows overwrite while order still pending and unpaid', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        'split_mode' => 'even',
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'by_items',
    ])
        ->assertOk();

    expect($order->fresh()->split_mode)->toBe('by_items');
});

it('rejects unknown split_mode value with 422', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'wat',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['split_mode']);
});

it('rejects missing split_mode field with 422', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['split_mode']);
});

it('returns 404 for non-existent order', function () {
    $this->postJson('/api/v1/customer/orders/'.Str::uuid()->toString().'/split-mode', [
        'split_mode' => 'even',
    ])
        ->assertStatus(404);
});

it('returns 422 when order is closed', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        'status' => 'closed',
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'ORDER_FINALIZED');
});

it('returns 422 when order is voided', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        'status' => 'voided',
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'by_items',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'ORDER_FINALIZED');
});

// ---------------------------------------------------------------------------
// plan-039 follow-up — split_count / split_people_count sub-feature. This was
// shipped without any test coverage; the cases below pin the contract:
// by_people persists the headcount, other modes reject it, the count is
// bounded [2,99], and switching mode clears any stale count.
// ---------------------------------------------------------------------------

it('persists split_people_count when by_people + split_count given', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 4,
    ])
        ->assertOk()
        ->assertJsonPath('data.split_mode', 'even')
        ->assertJsonPath('data.split_people_count', 4);

    $fresh = $order->fresh();
    expect($fresh->split_mode)->toBe('even');
    expect($fresh->split_people_count)->toBe(4);
});

it('by_people without split_count stores a null people count', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
    ])
        ->assertOk()
        ->assertJsonPath('data.split_people_count', null);

    expect($order->fresh()->split_people_count)->toBeNull();
});

it('clears a stale split_people_count when switching to by_items', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        'split_mode' => 'even',
        'split_people_count' => 5,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'by_items',
    ])
        ->assertOk()
        ->assertJsonPath('data.split_people_count', null);

    $fresh = $order->fresh();
    expect($fresh->split_mode)->toBe('by_items');
    expect($fresh->split_people_count)->toBeNull();
});

it('rejects split_count when split_mode is not by_people', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'by_items',
        'split_count' => 3,
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'SPLIT_COUNT_INVALID_FOR_MODE');

    // nothing persisted
    expect($order->fresh()->split_mode)->toBeNull();
});

it('rejects split_count below the minimum of 2', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 1,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['split_count']);
});

it('rejects split_count above the maximum of 99', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 100,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['split_count']);
});

it('accepts the inclusive split_count boundaries 2 and 99', function (int $count) {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => $count,
    ])
        ->assertOk()
        ->assertJsonPath('data.split_people_count', $count);

    expect($order->fresh()->split_people_count)->toBe($count);
})->with([2, 99]);

it('overwrites a stored people-count when re-posting by_people with a new count', function () {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        'split_mode' => 'even',
        'split_people_count' => 4,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 6,
    ])
        ->assertOk()
        ->assertJsonPath('data.split_people_count', 6);

    expect($order->fresh()->split_people_count)->toBe(6);
});

it('clears a stored people-count when re-posting by_people WITHOUT a count', function () {
    // Same mode, omitted count → the "re-set every call" rule must null the
    // stale headcount rather than silently keeping the earlier 4.
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        'split_mode' => 'even',
        'split_people_count' => 4,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
    ])
        ->assertOk()
        ->assertJsonPath('data.split_people_count', null);

    expect($order->fresh()->split_people_count)->toBeNull();
});

it('preserves the stored people-count when a locked re-edit is rejected', function () {
    // paid_amount > 0 → 409 before any validation/write; the earlier
    // headcount must survive untouched so /split-status keeps hard-locking.
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        'paid_amount' => 1000,
        'split_mode' => 'even',
        'split_people_count' => 3,
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 9,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'SPLIT_MODE_LOCKED');

    $fresh = $order->fresh();
    expect($fresh->split_mode)->toBe('even');
    expect($fresh->split_people_count)->toBe(3);
});

/*
 * #2860 — lệch phiên bản fleet, đo QUA ENDPOINT.
 *
 * Vì sao phải qua endpoint chứ không chỉ gọi thẳng normalizer: `$request->validate()`
 * **strip mọi khoá không có rule**, nên một giá trị được normalizer chấp nhận
 * vẫn có thể bị validator chặn trước khi tới nơi — và bài service-level sẽ xanh
 * trong khi đường thật trả 422 (#2622). Đây chính xác là ca đó: luật `in:` và
 * normalizer là HAI thứ, phải cùng đúng.
 *
 * Thiết bị chưa cập nhật là trạng thái MẶC ĐỊNH, không phải ngoại lệ:
 * workstation chạy trên hai máy Windows không tự cập nhật, kiosk là app native
 * trên tablet.
 */
it('#2860 thiết bị cũ gửi tên cũ vẫn được nhận, và LƯU bằng canonical', function (string $legacy, string $canonical) {
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id, [
        // pay-online: đường duy nhất nhận `by_amount` (ADR-1 chặn nó ở counter-pay)
        'stripe_payment_intent_id' => 'pi_'.Str::random(8),
    ]);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => $legacy,
    ])
        ->assertOk()
        // Phản hồi cũng canonical: client cũ gửi tên cũ vẫn đọc được tên mới,
        // vì nó chỉ so với chuỗi nó vừa gửi ở rất ít chỗ — còn nếu ta trả lại
        // tên cũ thì cột và payload nói hai thứ khác nhau, và đó là cách từ vựng
        // thứ hai mọc lại.
        ->assertJsonPath('data.split_mode', $canonical);

    expect($order->fresh()->split_mode)->toBe($canonical);
})->with([
    ['by_people', 'even'],    // customer-web bản cũ
    ['custom', 'by_amount'],  // customer-web bản cũ
    ['equal', 'even'],        // pos-web / kiosk bản cũ
    ['even', 'even'],         // bản mới, phải đi qua nguyên vẹn
]);

it('#2860 chuỗi ngoài cả hai tập vẫn bị chặn 422', function () {
    // Nhận tên cũ KHÔNG có nghĩa là nhận bừa. Thiếu bài này thì một lượt sửa
    // `validationRule()` thành `['nullable','string']` sẽ không ai biết.
    $order = makeOrder($this->orgId, $this->brand->id, $this->shop->id);

    $this->postJson("/api/v1/customer/orders/{$order->id}/split-mode", [
        'split_mode' => 'by_seat',
    ])->assertStatus(422);
});
