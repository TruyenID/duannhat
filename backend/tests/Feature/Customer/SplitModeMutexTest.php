<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;

/**
 * Issue #407 — Mutex between `Chia đều` (by_people) and `Chia theo món`
 * (by_items). BE side: the existing /split-mode endpoint already returns
 * 409 SPLIT_MODE_LOCKED when `paid_amount > 0`, which is the hard-lock
 * enforcement we need. These tests pin the contract so a refactor can't
 * silently relax it.
 *
 * `Tùy chọn` (custom) does NOT stamp customer_orders.split_mode, so it
 * never participates in the mutex from the BE side — its only constraint
 * is the stripe_payment_intent_id check on counter-pay flows.
 */
beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create();
    $this->branch = Branch::factory()->create();

    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'total_amount' => 3000,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
        // stripe_payment_intent_id null → counter-pay path; reject custom.
        // Set to non-null when testing the online-pay path so /split-mode
        // accepts `custom` per ADR-1.
        'stripe_payment_intent_id' => 'pi_test_online',
    ]);
});

it('allows swapping by_people <-> by_items before any payment lands (soft-lock window)', function () {
    // First payer stamps by_people.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 3,
    ])->assertOk();

    $this->order->refresh();
    expect($this->order->split_mode)->toBe('even');
    expect((int) $this->order->split_people_count)->toBe(3);

    // Same first payer changes their mind to by_items.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'by_items',
    ])->assertOk();

    $this->order->refresh();
    expect($this->order->split_mode)->toBe('by_items');
    // split_people_count must be cleared so the kiosk doesn't pick up a
    // stale headcount from the previous by_people stamping.
    expect($this->order->split_people_count)->toBeNull();

    // And back to by_people again — explicit count must override.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 4,
    ])->assertOk();

    $this->order->refresh();
    expect($this->order->split_mode)->toBe('even');
    expect((int) $this->order->split_people_count)->toBe(4);
});

it('rejects ANY /split-mode change once a payment has succeeded (hard lock for both tabs)', function () {
    $this->order->update([
        'split_mode' => 'even',
        'split_people_count' => 3,
    ]);

    // First payment lands — this is what flips the lock from soft to hard.
    OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 1000,
        'status' => PaymentStatusEnum::Succeeded->value,
        'paid_at' => now(),
        'metadata' => [
            'flow' => 'split',
            'split_mode' => 'even',
            'split_count' => 3,
            'amount_per_person' => 1000,
        ],
    ]);
    $this->order->update(['paid_amount' => 1000]);

    // Attempting to switch to by_items is rejected.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'by_items',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'SPLIT_MODE_LOCKED');

    // Attempting to re-stamp by_people (same mode but maybe different
    // count) is ALSO rejected — the locked headcount is the authoritative
    // source the kiosk + customer-web both read.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 5,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'SPLIT_MODE_LOCKED');

    // DB state is unchanged from the pre-lock snapshot.
    $this->order->refresh();
    expect($this->order->split_mode)->toBe('even');
    expect((int) $this->order->split_people_count)->toBe(3);
});

it('allows by_items -> by_people swap before payment, just like the other direction', function () {
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'by_items',
    ])->assertOk();

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 2,
    ])->assertOk();

    $this->order->refresh();
    expect($this->order->split_mode)->toBe('even');
    expect((int) $this->order->split_people_count)->toBe(2);
});

it('hard-locks the by_items mode the same way as by_people once a payment lands', function () {
    $this->order->update(['split_mode' => 'by_items']);

    OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 800,
        'status' => PaymentStatusEnum::Succeeded->value,
        'paid_at' => now(),
        'metadata' => [
            'flow' => 'split',
            'split_mode' => 'by_items',
            'item_allocations' => [['item_id' => 'x', 'units' => 1]],
        ],
    ]);
    $this->order->update(['paid_amount' => 800]);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 2,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'SPLIT_MODE_LOCKED');
});

it('custom split_mode is accepted on online-pay flows even when a soft lock is set', function () {
    // Online-pay path (stripe_payment_intent_id set in beforeEach).
    $this->order->update(['split_mode' => 'even', 'split_people_count' => 3]);

    // Custom is the safety valve — it does NOT stamp split_mode (controller
    // currently does, but the FE consumer should treat custom as a no-op for
    // the mutex). The endpoint accepts it because there is no payment yet.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'by_amount',
    ])->assertOk();
});

it('owner who paid Chia đều cannot swap to Chia theo món (hard lock applies to owner)', function () {
    // Scenario the user clarified on issue #407 comment:
    //   1. Customer picks Chia đều, pays.
    //   2. Customer (same device, same session) tries to switch to Chia theo món.
    //   3. BE must reject — the lock applies even to the original payer.
    //
    // The BE-side guard (`paid_amount > 0` in setSplitMode) is identity-blind:
    // it rejects ANY caller, owner or guest. That's exactly the behavior the
    // issue requires — once money is committed, the mode is frozen for the
    // entire order, no exception for "I'm the one who paid".
    $this->order->update([
        'split_mode' => 'even',
        'split_people_count' => 3,
    ]);

    OrderPayment::factory()->create([
        'customer_order_id' => $this->order->id,
        'organization_id' => $this->organization->id,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'amount' => 1000,
        'status' => PaymentStatusEnum::Succeeded->value,
        'paid_at' => now(),
        'metadata' => [
            'flow' => 'split',
            'split_mode' => 'even',
            'split_count' => 3,
            'amount_per_person' => 1000,
        ],
    ]);
    $this->order->update(['paid_amount' => 1000]);

    // Owner attempts the cross-mode swap → 409.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'by_items',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'SPLIT_MODE_LOCKED');

    // Owner attempts to bump headcount on the SAME mode → also 409.
    // The mode is frozen, the headcount is part of the lock, no edits.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'even',
        'split_count' => 4,
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'SPLIT_MODE_LOCKED');

    // Owner attempts to fall back to custom → also 409. Tùy chọn is only
    // freely available BEFORE money has committed to a split mode.
    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'by_amount',
    ])
        ->assertStatus(409)
        ->assertJsonPath('error', 'SPLIT_MODE_LOCKED');

    // State unchanged regardless of how many times owner POSTs.
    $this->order->refresh();
    expect($this->order->split_mode)->toBe('even');
    expect((int) $this->order->split_people_count)->toBe(3);
});

it('custom split_mode is rejected on counter-pay flows (no Stripe intent)', function () {
    // Counter-pay: stripe_payment_intent_id is NULL.
    $this->order->update(['stripe_payment_intent_id' => null]);

    $this->postJson("/api/v1/customer/orders/{$this->order->id}/split-mode", [
        'split_mode' => 'by_amount',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error', 'SPLIT_MODE_INVALID_FOR_COUNTER');
});
