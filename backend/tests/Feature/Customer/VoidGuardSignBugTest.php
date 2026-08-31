<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * #816 — the #547 void guard had a SIGN bug, so #547 false-closed.
 *
 * A refund is written as TWO rows: the original keeps its +X and flips to
 * `refunded`, plus a new -X `succeeded` row. The guard summed `succeeded`
 * ALONE — dropping the +X original while keeping the -X refund row. Every
 * refunded payment therefore contributed -X instead of 0: a phantom credit
 * that cancels OTHER payments' real cash and lets the void through.
 *
 * VoidCollectedPaymentGuardTest could not see this. Its only multi-row case is
 * a single payment plus its own full refund — the one shape where the buggy
 * sum (-300) and the correct sum (0) BOTH pass the `> 0` check. It also
 * hand-built the refund rows with factories, so it could never catch the
 * ledger and the guard disagreeing.
 *
 * These tests fix both gaps: >1 independent payment, and the refund is written
 * by the REAL OrderPaymentService::refund() rather than assumed.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    // Cash keeps Stripe entirely out of refund() — no kill-switch, no API call.
    $this->method = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

function vgsOrder(string $status = 'paying', float $total = 1500): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-'.Str::random(6),
        'order_type' => 'dine_in',
        'status' => $status,
        'subtotal' => $total, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => $total, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

/** A cash payment that really landed in the drawer. */
function vgsCash(CustomerOrder $order, float $amount): OrderPayment
{
    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => test()->method->id,
        'amount' => $amount,
        'status' => PaymentStatusEnum::Succeeded->value,
        'paid_at' => now(),
        'refund_of_id' => null,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
    ]);
}

/** Drive the REAL refund path — never hand-build the refund ledger. */
function vgsRefund(OrderPayment $payment, ?float $amount = null): OrderPayment
{
    return app(OrderPaymentService::class)->refund(
        $payment,
        $amount === null ? [] : ['amount' => $amount],
    );
}

function vgsExpectVoidBlocked(CustomerOrder $order, string $expectedCollected): void
{
    try {
        app(CustomerOrderService::class)->voidOrder($order, ['void_reason' => 'x']);
    } catch (HttpResponseException $e) {
        $resp = $e->getResponse();
        expect($resp->getStatusCode())->toBe(409);
        $body = json_decode($resp->getContent(), true);
        expect($body['code'])->toBe('void_blocked_collected_payment')
            ->and($body['collected_amount'])->toBe($expectedCollected);
        expect($order->fresh()->status->value)->toBe('paying');

        return;
    }

    $this->fail(
        "VOID SUCCEEDED while {$expectedCollected} is still collected — guard sign bug (#816)."
    );
}

// ── The invariant the guard depends on ───────────────────────────────────────

it('writes a refund as (+X refunded) + (-X succeeded) — the shape the guard must net', function () {
    $order = vgsOrder();
    $paid = vgsCash($order, 500);

    vgsRefund($paid);

    $ledger = OrderPayment::where('customer_order_id', $order->id)
        ->orderBy('amount', 'desc')
        ->get(['amount', 'status', 'refund_of_id']);

    expect($ledger)->toHaveCount(2);

    // Original: keeps its POSITIVE amount, flips to `refunded`.
    expect((float) $ledger[0]->amount)->toBe(500.0)
        ->and($ledger[0]->status->value)->toBe('refunded')
        ->and($ledger[0]->refund_of_id)->toBeNull();

    // Refund row: NEGATIVE amount, status `succeeded`.
    expect((float) $ledger[1]->amount)->toBe(-500.0)
        ->and($ledger[1]->status->value)->toBe('succeeded')
        ->and($ledger[1]->refund_of_id)->toBe($paid->id);

    // Hence: summing `succeeded` alone yields -500, not 0. That is the bug.
    $succeededOnly = (float) OrderPayment::where('customer_order_id', $order->id)
        ->where('status', PaymentStatusEnum::Succeeded->value)
        ->sum('amount');
    expect($succeededOnly)->toBe(-500.0);
});

// ── Cloud void ───────────────────────────────────────────────────────────────

it('blocks the cloud void when a split-bill refund masks a second diner\'s cash', function () {
    // Diner A and diner B each hand over 500 cash on a 1500 order.
    $order = vgsOrder(total: 1500);
    $dinerA = vgsCash($order, 500);
    vgsCash($order, 500); // diner B — this cash is STILL IN THE DRAWER

    // Manager refunds diner A in full. Buggy guard: (-500) + 500 = 0 → void allowed.
    vgsRefund($dinerA);

    // Correct: 500 (A, refunded) + 500 (B) - 500 (refund row) = 500 still held.
    vgsExpectVoidBlocked($order, '500.00');
});

it('blocks the cloud void when a 1-unit refund masks the rest of the cash', function () {
    $order = vgsOrder(total: 1500);
    $paid = vgsCash($order, 1000);

    // Refund a single unit. Buggy guard: netCollected = -1 → not > 0 → void allowed,
    // orphaning 999 in the drawer.
    vgsRefund($paid, 1);

    vgsExpectVoidBlocked($order, '999.00');
});

// ── Workstation LAN void (same guard, same bug) ──────────────────────────────

it('blocks the workstation LAN void on the same split-bill refund', function () {
    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $order = vgsOrder(total: 1500);
    $dinerA = vgsCash($order, 500);
    vgsCash($order, 500);
    vgsRefund($dinerA);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson("/api/v1/workstation/orders/{$order->id}/void", ['void_reason' => 'lan void'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'void_blocked_collected_payment')
        ->assertJsonPath('collected_amount', '500.00');

    expect($order->fresh()->status->value)->toBe('paying');
});

// ── Phase 2 — the LAN path never guarded `closed` at all ─────────────────────

it('blocks the workstation LAN void of a closed order (cloud already refuses this)', function () {
    $token = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $token,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $order = vgsOrder(status: 'closed', total: 1500);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson("/api/v1/workstation/orders/{$order->id}/void", ['void_reason' => 'lan void'])
        ->assertStatus(409);

    expect($order->fresh()->status->value)->toBe('closed');
});
