<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Table;
use App\Models\User;
use App\Models\Zone;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Str;

/*
 * Issue #554 — continueTableOrder must not close an UNPAID order.
 *
 * `closed` is the terminal "fully paid / completed" state that HQ revenue
 * aggregate() sums. Booking a still-owing order as closed inflates revenue
 * and silently drops the outstanding balance. Unpaid active orders must be
 * voided (excluded from revenue) when the table starts a fresh party.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);

    $pt = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);
});

function makeTableWithOrder(float $total, float $paid, string $status = 'open'): array
{
    $zone = Zone::factory()->for(test()->branch, 'branch')->create([
        'organization_id' => test()->orgId,
    ]);
    $table = Table::factory()->for(test()->branch, 'branch')->for($zone, 'zone')->create([
        'organization_id' => test()->orgId,
        'status' => 'occupied',
    ]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-C'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'order_type' => 'dine_in',
        'status' => $status,
        'subtotal' => $total,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => $paid,
        'total_tip' => 0,
        'opened_at' => now(),
        'table_id' => $table->id,
        'created_by_id' => test()->user->id,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    // table_id is guarded on the model; the continue-table query filters on it.
    $order->forceFill(['table_id' => $table->id])->save();

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 1,
        'unit_price' => $total,
        'original_unit_price' => $total,
        'subtotal' => $total,
        'status' => 'served',
        'served_at' => now(),
        'tax_rate' => 0,
    ]);

    $table->update(['current_order_id' => $order->id]);

    return [$table, $order];
}

function callContinue(Table $table): void
{
    app(CustomerOrderService::class)->continueTableOrder(test()->branch->id, [
        'table_ids' => [$table->id],
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'created_by_id' => test()->user->id,
        'items' => [
            ['product_sku_id' => test()->sku->id, 'quantity' => 1],
        ],
    ]);
}

it('voids an UNPAID active order instead of closing it (#554)', function () {
    [$table, $unpaid] = makeTableWithOrder(total: 1000, paid: 0);

    callContinue($table);

    // The still-owing order must NOT be booked as revenue.
    expect($unpaid->fresh()->status->value)->toBe('voided');

    $agg = app(CustomerOrderService::class)->aggregate(['branch_id' => $this->branch->id]);
    expect($agg['total_revenue'])->toBe(0);
});

it('still closes a fully-paid active order (#554 control)', function () {
    [$table, $paid] = makeTableWithOrder(total: 1000, paid: 1000);

    callContinue($table);

    expect($paid->fresh()->status->value)->toBe('closed');

    $agg = app(CustomerOrderService::class)->aggregate(['branch_id' => $this->branch->id]);
    expect($agg['total_revenue'])->toBe(1000);
});
