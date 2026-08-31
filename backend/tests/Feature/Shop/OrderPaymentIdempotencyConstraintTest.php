<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * DB-level guarantees for the idempotency_key constraint. The legacy schema
 * declared idempotency_key globally UNIQUE which silently rejected
 * cross-order key collisions (split-bill / concurrent retries). The fix
 * replaces that with a composite UNIQUE on (customer_order_id,
 * idempotency_key) so per-order retries still dedupe while different orders
 * can legitimately reuse the same client-supplied key.
 *
 * These assertions sidestep OrderPaymentService + closing flow so the
 * constraint is verified in isolation from unrelated business-logic bugs.
 */

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
        'slug' => 'idem-constraint',
        'is_active' => true,
    ]);
    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);
    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
});

function makeConstraintOrder(string $orgId, string $brandId, string $branchId, string $customerId): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-CON-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => 1000,
        'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => $customerId,
        'branch_id' => $branchId,
        'brand_id' => $brandId,
        'organization_id' => $orgId,
    ]);
}

function insertConstraintPayment(string $orgId, string $brandId, string $branchId, string $orderId, string $methodId, ?string $key): string
{
    $id = (string) Str::uuid();
    DB::table('order_payments')->insert([
        'id' => $id,
        'payment_code' => 'PAY-CON-'.Str::random(6),
        'amount' => 500,
        'tip_amount' => 0,
        'status' => 'succeeded',
        'paid_at' => now(),
        'idempotency_key' => $key,
        'payment_method_id' => $methodId,
        'customer_order_id' => $orderId,
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('allows the same idempotency_key on payments for different orders', function () {
    $orderA = makeConstraintOrder($this->orgId, $this->brand->id, $this->shop->id, $this->customer->id);
    $orderB = makeConstraintOrder($this->orgId, $this->brand->id, $this->shop->id, $this->customer->id);
    $key = (string) Str::uuid();

    $idA = insertConstraintPayment($this->orgId, $this->brand->id, $this->shop->id, $orderA->id, $this->cashMethod->id, $key);
    $idB = insertConstraintPayment($this->orgId, $this->brand->id, $this->shop->id, $orderB->id, $this->cashMethod->id, $key);

    expect(OrderPayment::where('idempotency_key', $key)->count())->toBe(2);
    expect($idA)->not->toBe($idB);
});

it('rejects duplicate idempotency_key within the same order', function () {
    $order = makeConstraintOrder($this->orgId, $this->brand->id, $this->shop->id, $this->customer->id);
    $key = (string) Str::uuid();

    insertConstraintPayment($this->orgId, $this->brand->id, $this->shop->id, $order->id, $this->cashMethod->id, $key);

    expect(fn () => insertConstraintPayment($this->orgId, $this->brand->id, $this->shop->id, $order->id, $this->cashMethod->id, $key))
        ->toThrow(QueryException::class);
});

it('allows multiple null idempotency_keys without collision', function () {
    $order = makeConstraintOrder($this->orgId, $this->brand->id, $this->shop->id, $this->customer->id);

    insertConstraintPayment($this->orgId, $this->brand->id, $this->shop->id, $order->id, $this->cashMethod->id, null);
    insertConstraintPayment($this->orgId, $this->brand->id, $this->shop->id, $order->id, $this->cashMethod->id, null);

    expect(OrderPayment::where('customer_order_id', $order->id)->whereNull('idempotency_key')->count())->toBe(2);
});
