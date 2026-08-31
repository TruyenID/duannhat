<?php

/**
 * Plan-038 audit HIGH (test-gap) — debt (ghi nợ) behaviour had no feature
 * coverage beyond the migration/seeder sanity checks. This file exercises:
 *
 *   1. Validation — `on_account` payment on an order with NO customer_id is
 *      rejected 422 `customer_required_for_debt` (OrderPaymentStoreRequest),
 *      and accepted when a customer is present.
 *   2. Aggregation — GET /shops/{shop}/debts sums confirmed on_account rows
 *      grouped by customer (money EXACT).
 *   3. Settlement — a payment carrying metadata.settles_payment_id removes the
 *      original debt from the /debts aggregation.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductSku;
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
        'slug' => 'debt-flow-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($role, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'An',
        'last_name' => 'Nguyen',
        'phone' => '0900000001',
    ]);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => 3000,
        'is_active' => true,
    ]);

    // `type` is not a factory/fillable attribute — mirror DebtTenantScopingTest
    // and stamp it with a raw update.
    $this->debtMethod = PaymentMethod::factory()->create([
        'code' => 'debt',
        'name' => 'Ghi nợ',
        'is_auto_confirm' => true,
        'requires_tendered' => false,
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    DB::table('payment_methods')->where('id', $this->debtMethod->id)->update(['type' => 'on_account']);

    // Cash method for the settlement payment (a debt is settled by real cash,
    // NOT by another on_account row).
    $this->cashMethod = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
    ]);
    DB::table('payment_methods')->where('id', $this->cashMethod->id)->update(['type' => 'cash']);
});

function seedDebtFlowOrder(int $total, ?string $customerId): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-DBT-'.Str::random(4),
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'subtotal' => $total,
        'discount_amount' => 0, 'service_charge' => 0, 'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(), 'checkout_at' => now(),
        'customer_id' => $customerId,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1, 'unit_price' => $total, 'original_unit_price' => $total, 'subtotal' => $total,
        'status' => 'served', 'served_at' => now(),
        'tax_rate' => 0,
    ]);

    return $order;
}

/** Insert a confirmed on_account payment directly for aggregation tests. */
function seedConfirmedDebt(CustomerOrder $order, int $amount, ?array $metadata = null): string
{
    $id = (string) Str::uuid();
    DB::table('order_payments')->insert([
        'id' => $id,
        'payment_code' => strtoupper(Str::random(10)),
        'amount' => $amount,
        'tip_amount' => 0,
        // #1822/#1921 — `succeeded`, KHÔNG phải `confirmed`. Từ vựng cũ đã bị
        // xoá cùng `PaymentStatusCompatibility`; `PaymentStatusEnum` không có
        // case `Confirmed` nào, nên một hàng `'confirmed'` là trạng thái BẤT
        // KHẢ mà production không sinh ra được. Fixture cũ ghi nó nên
        // `DebtController` (đã siết về enum) lọc mất và danh sách nợ ra rỗng.
        'status' => 'succeeded',
        'payment_method_id' => test()->debtMethod->id,
        'customer_order_id' => $order->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
        'metadata' => $metadata ? json_encode($metadata) : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('rejects an on_account payment when the order has no customer (422 customer_required_for_debt)', function () {
    $order = seedDebtFlowOrder(3000, customerId: null);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->debtMethod->id,
            'amount' => 3000,
        ])
        ->assertStatus(422)
        ->assertJsonPath('errors.payment_method_id.0', 'customer_required_for_debt');

    expect(DB::table('order_payments')->where('customer_order_id', $order->id)->count())->toBe(0);
});

it('accepts an on_account payment when the order has a customer', function () {
    $order = seedDebtFlowOrder(3000, customerId: $this->customer->id);

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->debtMethod->id,
            'amount' => 3000,
        ])
        ->assertCreated();

    expect(DB::table('order_payments')->where('customer_order_id', $order->id)->count())->toBe(1);
});

it('aggregates confirmed on_account debts grouped by customer with an EXACT sum', function () {
    $o1 = seedDebtFlowOrder(125000, customerId: $this->customer->id);
    $o2 = seedDebtFlowOrder(75000, customerId: $this->customer->id);
    seedConfirmedDebt($o1, 125000);
    seedConfirmedDebt($o2, 75000);

    $response = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/debts")
        ->assertOk();

    $rows = $response->json('data');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['customer_id'])->toBe($this->customer->id)
        // Full name, not last_name alone — see the phone-only case below.
        ->and($rows[0]['customer_name'])->toBe('An Nguyen')
        ->and($rows[0]['customer_phone'])->toBe('0900000001')
        ->and((int) $rows[0]['open_debt_count'])->toBe(2)
        // Money EXACT — 125,000 + 75,000.
        ->and((float) $rows[0]['open_debt_total'])->toBe(200000.0);
});

/*
 * POS attaches a customer by PHONE (createOrder → findOrCreateByPhone), which
 * yields a placeholder first_name, NO last_name and NO tax_code. The endpoint
 * used to select `MAX(c.last_name)` as the name and never returned the phone at
 * all, so every debtor booked from the POS came back nameless and unsearchable —
 * the cashier had literally nothing to identify the row by.
 */
it('identifies a phone-only customer (the shape POS actually creates)', function () {
    $phoneOnly = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'Khách',
        'last_name' => null,
        'phone' => '0987654312',
        'tax_code' => null,
    ]);

    $order = seedDebtFlowOrder(50000, $phoneOnly->id);
    seedConfirmedDebt($order, 50000);

    $rows = $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/debts")
        ->assertOk()
        ->json('data');

    $row = collect($rows)->firstWhere('customer_id', $phoneOnly->id);

    expect($row)->not->toBeNull()
        // Name falls out of first_name alone — no trailing space from the
        // missing last_name.
        ->and($row['customer_name'])->toBe('Khách')
        // The phone is the ONLY handle the cashier has. It must be on the wire.
        ->and($row['customer_phone'])->toBe('0987654312')
        ->and($row['customer_tax_code'])->toBeNull()
        ->and((float) $row['open_debt_total'])->toBe(50000.0);
});

it('excludes a debt that has been settled via metadata.settles_payment_id', function () {
    $debtOrder = seedDebtFlowOrder(90000, customerId: $this->customer->id);
    $debtId = seedConfirmedDebt($debtOrder, 90000);

    // Before settlement — the debt is open.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/debts")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    // Settlement payment (real cash) points back at the original debt.
    DB::table('order_payments')->insert([
        'id' => (string) Str::uuid(),
        'payment_code' => strtoupper(Str::random(10)),
        'amount' => 90000,
        'tip_amount' => 0,
        // #1822/#1921 — `succeeded`, KHÔNG phải `confirmed`. Từ vựng cũ đã bị
        // xoá cùng `PaymentStatusCompatibility`; `PaymentStatusEnum` không có
        // case `Confirmed` nào, nên một hàng `'confirmed'` là trạng thái BẤT
        // KHẢ mà production không sinh ra được. Fixture cũ ghi nó nên
        // `DebtController` (đã siết về enum) lọc mất và danh sách nợ ra rỗng.
        'status' => 'succeeded',
        'payment_method_id' => $this->cashMethod->id,
        'customer_order_id' => $debtOrder->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'metadata' => json_encode(['settles_payment_id' => $debtId]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The settled debt drops out of the aggregation.
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/debts")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
