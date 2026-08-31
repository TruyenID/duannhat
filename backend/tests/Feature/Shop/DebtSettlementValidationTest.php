<?php

/**
 * Plan-038 audit HIGH (test-gap) — debt-settlement guard matrix.
 *
 * OrderPaymentStoreRequest::withValidator enforces five invariants on a
 * settlement payment carrying `metadata.settles_payment_id`, but only the
 * happy "excludes a settled debt" path had coverage (DebtPaymentFlowTest).
 * Each failure branch is a money-integrity guard — settling the wrong debt,
 * an unconfirmed debt, a non-debt payment, or double-settling one debt all
 * corrupt the /debts ledger. This suite pins every 422 key:
 *
 *   settles_payment_not_found · settles_wrong_type · settles_not_confirmed
 *
 * The status check now compares the backing enum VALUE (plan-038 logic fix),
 * so a genuinely-confirmed (succeeded) debt settles and the downstream
 * settles_already_settled guard is reachable — both pinned below.
 *
 * One guard remains UNREACHABLE (a separate, out-of-scope bug):
 *
 *   - settles_wrong_customer — compares `$orig->customer_id`, but
 *     `order_payments` has no `customer_id` column, so that attribute is
 *     always null and the guard never fires (another customer's debt can be
 *     referenced without being blocked by this rule).
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
        'slug' => 'debt-settle-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customerA = Customer::factory()->create(['organization_id' => $this->orgId, 'last_name' => 'AlphaCo']);

    $this->debtMethod = PaymentMethod::factory()->create([
        'code' => 'debt', 'name' => 'Ghi nợ', 'is_auto_confirm' => true, 'requires_tendered' => false,
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
    ]);
    DB::table('payment_methods')->where('id', $this->debtMethod->id)->update(['type' => 'on_account']);

    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->shop->id,
    ]);
    DB::table('payment_methods')->where('id', $this->cashMethod->id)->update(['type' => 'cash']);
});

/** Order with a customer + one served line. */
function settleOrder(string $customerId, int $total = 90000): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->shop->id,
        'customer_id' => $customerId,
        'status' => 'checkout',
        'total_amount' => $total,
    ]);
}

/** Insert one payment row directly (used to seed the debt being settled). */
function settlePayment(CustomerOrder $order, string $methodId, string $status, int $amount): string
{
    $id = (string) Str::uuid();
    DB::table('order_payments')->insert([
        'id' => $id,
        'payment_code' => strtoupper(Str::random(10)),
        'amount' => $amount,
        'tip_amount' => 0,
        'status' => $status,
        'payment_method_id' => $methodId,
        'customer_order_id' => $order->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/** POST a cash settlement referencing $settlesId onto $order. */
function postSettlement(CustomerOrder $order, string $settlesId, int $amount = 90000)
{
    return test()->actingAs(test()->manager)
        ->postJson('/api/v1/shops/'.test()->shop->slug."/orders/{$order->id}/payments", [
            'payment_method_id' => test()->cashMethod->id,
            'amount' => $amount,
            'tendered_amount' => $amount, // cash method requires_tendered
            'metadata' => ['settles_payment_id' => $settlesId],
        ]);
}

it('rejects a settlement pointing at a non-existent payment (422 settles_payment_not_found)', function () {
    $order = settleOrder($this->customerA->id);

    postSettlement($order, (string) Str::uuid())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metadata.settles_payment_id' => 'settles_payment_not_found']);
});

it('rejects settling a non-on_account payment (422 settles_wrong_type)', function () {
    $order = settleOrder($this->customerA->id);
    // The "debt" being settled is actually a confirmed CASH payment.
    $cashId = settlePayment($order, $this->cashMethod->id, 'succeeded', 90000);

    postSettlement($order, $cashId)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metadata.settles_payment_id' => 'settles_wrong_type']);
});

it('rejects settling an unconfirmed (refunded) debt (422 settles_not_confirmed)', function () {
    $order = settleOrder($this->customerA->id);
    $debtId = settlePayment($order, $this->debtMethod->id, 'refunded', 90000);

    postSettlement($order, $debtId)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metadata.settles_payment_id' => 'settles_not_confirmed']);
});

/*
 * REGRESSION (plan-038 logic fix). OrderPayment casts `status` to
 * PaymentStatusEnum, so the old `$orig->status !== 'succeeded'` compared an
 * enum instance against a string (never equal) and fired for EVERY on_account
 * debt — a genuinely-confirmed debt could not be settled. The guard now
 * compares `$orig->status->value`, so a succeeded debt settles.
 */
it('settles a succeeded on_account debt (enum-comparison fix)', function () {
    // The debt lives on its own (already fully-paid) order; the cash settlement
    // is a fresh transaction that pays it off, so it does not overpay the debt
    // order.
    $debtOrder = settleOrder($this->customerA->id);
    $debtId = settlePayment($debtOrder, $this->debtMethod->id, 'succeeded', 90000);

    postSettlement(settleOrder($this->customerA->id), $debtId)->assertCreated();

    // The settlement payment linked back to the original debt was persisted.
    expect(
        DB::table('order_payments')
            ->where('metadata->settles_payment_id', $debtId)
            ->exists()
    )->toBeTrue();
});

it('rejects double-settling the same debt (422 settles_already_settled)', function () {
    $debtOrder = settleOrder($this->customerA->id);
    $debtId = settlePayment($debtOrder, $this->debtMethod->id, 'succeeded', 90000);

    // First settlement succeeds.
    postSettlement(settleOrder($this->customerA->id), $debtId)->assertCreated();

    // Second settlement of the same debt is blocked.
    postSettlement(settleOrder($this->customerA->id), $debtId)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['metadata.settles_payment_id' => 'settles_already_settled']);
});
