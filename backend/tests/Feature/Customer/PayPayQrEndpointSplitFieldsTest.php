<?php

/**
 * #1296 — the mint endpoint's request contract with the dine-in bill.
 *
 * `amount` alone was enough for takeaway, where one payer settles one order. A
 * dine-in bill splits, so the request also has to say HOW the payer arrived at
 * their share — using the same field names as the Stripe split endpoint beside
 * it, or one order ends up described two different ways depending on which
 * gateway happened to collect which quarter of it.
 *
 * These orders sit on a branch with no PayPay configured, which is the point: a
 * request that CLEARS validation is then refused on the merits, with
 * `code: PAYPAY_NOT_AVAILABLE` and no `errors` bag. That tells the two 422s
 * apart — "I do not understand this field" versus "this branch has no PayPay" —
 * which a bare status assertion could not. What the service then does with the
 * fields is covered at service level by Plan054PayPayMintRegressionTest.
 */

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Omnify\Enums\CustomerOrderStatusEnum;

uses()->group('payment');

beforeEach(function () {
    // Pin the branch to "PayPay not configured" rather than inheriting whatever
    // the ambient env has. That is what makes the two 422s distinguishable: a
    // request that clears validation is then refused on the merits with
    // `PAYPAY_NOT_AVAILABLE`, so a validation failure cannot hide behind it.
    config([
        'services.paypay.api_key' => '',
        'services.paypay.api_secret' => '',
        'services.paypay.merchant_id' => '',
    ]);
});

function dineInPayPayOrder(): CustomerOrder
{
    $branch = Branch::factory()->create(['currency' => 'JPY']);

    return CustomerOrder::factory()->create([
        'branch_id' => $branch->id,
        'order_type' => 'dine_in',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);
}

it('accepts a per-dish split as a well-formed request', function () {
    $order = dineInPayPayOrder();

    $this->postJson("/api/v1/customer/orders/{$order->id}/paypay-qr", [
        'amount' => 750,
        'split_type' => 'by_items',
        'item_allocations' => [
            ['item_id' => 'item-salad', 'units' => 1],
            ['item_id' => 'item-soup', 'units' => 2],
        ],
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYPAY_NOT_AVAILABLE')
        ->assertJsonMissingPath('errors');
});

it('accepts the headcount of an even split', function () {
    $order = dineInPayPayOrder();

    $this->postJson("/api/v1/customer/orders/{$order->id}/paypay-qr", [
        'amount' => 1000,
        'split_type' => 'by_people',
        'split_count' => 3,
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYPAY_NOT_AVAILABLE');
});

it('still accepts a bare mint, which is what takeaway sends', function () {
    $order = dineInPayPayOrder();

    $this->postJson("/api/v1/customer/orders/{$order->id}/paypay-qr", [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'PAYPAY_NOT_AVAILABLE')
        ->assertJsonMissingPath('errors');
});

it('rejects a split strategy it does not know rather than silently dropping it', function () {
    $order = dineInPayPayOrder();

    $this->postJson("/api/v1/customer/orders/{$order->id}/paypay-qr", [
        'amount' => 500,
        'split_type' => 'by_vibes',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('split_type');
});

it('rejects an allocation line with no units', function () {
    $order = dineInPayPayOrder();

    $this->postJson("/api/v1/customer/orders/{$order->id}/paypay-qr", [
        'amount' => 500,
        'split_type' => 'by_items',
        'item_allocations' => [['item_id' => 'item-salad']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('item_allocations.0.units');
});

it('rejects a headcount of one, which is not a split', function () {
    $order = dineInPayPayOrder();

    $this->postJson("/api/v1/customer/orders/{$order->id}/paypay-qr", [
        'amount' => 500,
        'split_type' => 'by_people',
        'split_count' => 1,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('split_count');
});
