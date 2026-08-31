<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\Zone;
use Illuminate\Support\Str;

/**
 * #491 — the Stripe (online) payment webhook must release the dine-in table
 * to the branch's EFFECTIVE "table status after payment" (free|cleaning),
 * resolved shop ?? HQ brand default ?? free — the same policy the
 * counter/POS close path (OrderClosingService) already honours.
 *
 * Before the fix both Stripe release points hard-coded `free`, so an online
 * payer silently bypassed a shop that wanted paid tables to go to `cleaning`.
 * These tests drive the real webhook end-to-end for both the full-payment and
 * final-split-slice release points.
 */
beforeEach(function () {
    config([
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
    ]);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->customer = Customer::factory()->selfRegistered()->create();

    $zone = Zone::factory()->for($this->branch, 'branch')->create(['organization_id' => $this->orgId]);
    $this->table = Table::factory()->for($this->branch, 'branch')->for($zone, 'zone')
        ->create(['organization_id' => $this->orgId]);

    $this->makeStripeEvent = function (string $type, array $dataObject): array {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $dataObject],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_secret_xyz');

        return ['payload' => $payload, 'header' => "t={$timestamp},v1={$signature}"];
    };

    $this->postWebhook = fn (string $payload, string $signature) => $this->call(
        'POST', '/api/v1/customer/stripe/webhook', [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    // Build an occupied dine-in order sitting on the table.
    $this->makeOccupiedOrder = function (string $piId, float $total = 1500, float $paid = 0): CustomerOrder {
        $order = CustomerOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'order_type' => 'dine_in',
            'stripe_payment_intent_id' => $piId,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'status' => 'open',
        ]);
        Table::where('id', $this->table->id)->update([
            'current_order_id' => $order->id,
            'status' => 'occupied',
        ]);

        if ($paid > 0) {
            OrderPayment::factory()->succeeded()->create([
                'customer_order_id' => $order->id,
                'organization_id' => $this->orgId,
                'branch_id' => $this->branch->id,
                'brand_id' => $this->brand->id,
                'amount' => $paid,
                'reference_no' => 'pi_prior_'.$piId,
            ]);
        }

        return $order;
    };
});

it('releases a paid table to FREE by default on Stripe full payment', function () {
    ($this->makeOccupiedOrder)('pi_full_default', 1500);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent', 'id' => 'pi_full_default', 'amount' => 1500,
    ]);
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(Table::find($this->table->id)->status->value)->toBe('free');
});

it('releases a paid table to CLEANING on Stripe full payment when the shop opts in', function () {
    ShopOrderSetting::create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        // #815 — column DB-defaults to VND; pin JPY so the branch is JPY-priced and
        // the jpy charge events below are not refused by the currency-mismatch guard.
        'currency_code' => 'JPY',
        'table_status_after_payment' => 'cleaning',
    ]);
    ($this->makeOccupiedOrder)('pi_full_cleaning', 1500);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent', 'id' => 'pi_full_cleaning', 'amount' => 1500,
    ]);
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(Table::find($this->table->id)->status->value)->toBe('cleaning');
});

it('inherits the HQ brand default (cleaning) on Stripe full payment when the shop value is NULL', function () {
    BrandOrderPolicy::create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_table_status_after_payment' => 'cleaning',
    ]);
    ($this->makeOccupiedOrder)('pi_full_hq', 1500);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent', 'id' => 'pi_full_hq', 'amount' => 1500,
    ]);
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(Table::find($this->table->id)->status->value)->toBe('cleaning');
});

it('releases the table to CLEANING when the FINAL split slice settles the order online', function () {
    ShopOrderSetting::create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        // #815 — column DB-defaults to VND; pin JPY so the branch is JPY-priced and
        // the jpy charge events below are not refused by the currency-mismatch guard.
        'currency_code' => 'JPY',
        'table_status_after_payment' => 'cleaning',
    ]);
    // 2000 total, 1200 already paid — this split slice pays the remaining 800.
    $order = ($this->makeOccupiedOrder)('pi_split_final', 2000, 1200);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_split_final',
        'amount' => 800,
        'metadata' => ['flow' => 'split', 'order_id' => $order->id],
    ]);
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect($order->fresh()->paid_amount)->toEqual(2000);
    expect(Table::find($this->table->id)->status->value)->toBe('cleaning');
});

it('keeps the table OCCUPIED on a non-final split slice regardless of the cleaning setting', function () {
    ShopOrderSetting::create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        // #815 — column DB-defaults to VND; pin JPY so the branch is JPY-priced and
        // the jpy charge events below are not refused by the currency-mismatch guard.
        'currency_code' => 'JPY',
        'table_status_after_payment' => 'cleaning',
    ]);
    // 2000 total, nothing paid — this slice pays 800, order still owes 1200.
    $order = ($this->makeOccupiedOrder)('pi_split_partial', 2000, 0);

    $event = ($this->makeStripeEvent)('payment_intent.succeeded', [
        'object' => 'payment_intent',
        'id' => 'pi_split_partial',
        'amount' => 800,
        'metadata' => ['flow' => 'split', 'order_id' => $order->id],
    ]);
    ($this->postWebhook)($event['payload'], $event['header'])->assertOk();

    expect(Table::find($this->table->id)->status->value)->toBe('occupied');
});
