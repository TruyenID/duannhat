<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\Table;
use App\Models\User;
use App\Models\Zone;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\OrderItemStatusEnum;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Plan-047 T2.12 (#1090) — POST /shops/{slug}/orders/continue-table now rides
 * ContinueTableOrderCommand through the typed facade. These are BUSINESS tests:
 * take-over must never book phantom revenue (#554), never leak the previous
 * party's money into the new bill, and always leave every table pointing at
 * exactly one live order.
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

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'continue-table-typed-shop',
        'is_active' => true,
    ]);

    $managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);
    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
        'current_order_id' => null,
    ]);

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
        'selling_price' => 800,
        'is_active' => true,
    ]);
});

/** Seat a party on the table: an active order carrying real money. */
function seatedOrder(float $total, float $paid, string $status = 'dining'): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-T'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
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
        'created_by_id' => test()->manager->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $order->forceFill(['table_id' => test()->table->id])->save();

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

    test()->table->update(['status' => 'occupied', 'current_order_id' => $order->id]);

    return $order;
}

function postContinue(array $overrides = []): TestResponse
{
    return test()->actingAs(test()->manager)->postJson(
        '/api/v1/shops/'.test()->shop->slug.'/orders/continue-table',
        array_merge([
            'table_ids' => [test()->table->id],
            'items' => [
                ['product_sku_id' => test()->sku->id, 'quantity' => 2],
            ],
        ], $overrides),
    );
}

// =========================================================================
//  Money integrity on take-over
// =========================================================================

it('closes a FULLY PAID order and books its ¥3,000 exactly once — the new party starts at ¥0 owed from the old bill', function () {
    $paidOrder = seatedOrder(total: 3000, paid: 3000);

    postContinue()->assertOk();

    $old = $paidOrder->fresh();
    expect($old->status)->toBe(CustomerOrderStatusEnum::Closed)
        ->and($old->closed_at)->not->toBeNull()
        // The settled bill is immutable: still ¥3,000 total / ¥3,000 paid.
        ->and((float) $old->total_amount)->toBe(3000.0)
        ->and((float) $old->paid_amount)->toBe(3000.0);

    $new = CustomerOrder::where('id', '!=', $paidOrder->id)->firstOrFail();
    // The new party's bill contains ONLY its own 2 × ¥800 — no carry-over.
    expect((float) $new->subtotal)->toBe(1600.0)
        ->and((float) $new->paid_amount)->toBe(0.0)
        ->and($new->status)->toBe(CustomerOrderStatusEnum::Open);
});

it('VOIDS an order still owing ¥2,500 instead of closing it — closing would book phantom revenue (#554)', function () {
    $unpaid = seatedOrder(total: 2500, paid: 0);

    postContinue()->assertOk();

    $old = $unpaid->fresh();
    expect($old->status)->toBe(CustomerOrderStatusEnum::Voided)
        ->and($old->void_reason)->toBe('auto_voided_unpaid_before_continue')
        ->and($old->closed_at)->toBeNull();

    // Every line of the walked-out bill is voided — no half-live items.
    $old->items->each(function ($item) {
        expect($item->status)->toBe(OrderItemStatusEnum::Voided)
            ->and($item->void_reason)->toBe('auto_voided_unpaid_before_continue');
    });
});

it('voids a PARTIALLY paid order (¥1,000 of ¥4,000) — a 25%-paid bill is not revenue', function () {
    $partial = seatedOrder(total: 4000, paid: 1000);

    postContinue()->assertOk();

    expect($partial->fresh()->status)->toBe(CustomerOrderStatusEnum::Voided);
});

// =========================================================================
//  Table rebinding
// =========================================================================

it('rebinds the table to the NEW order — never leaves it pointing at the retired one', function () {
    $old = seatedOrder(total: 1000, paid: 1000);

    postContinue()->assertOk();

    $new = CustomerOrder::where('id', '!=', $old->id)->firstOrFail();
    $table = $this->table->fresh();
    expect($table->current_order_id)->toBe($new->id)
        ->and($table->current_order_id)->not->toBe($old->id)
        ->and($table->status->value)->toBe('occupied');
});

it('is idempotent on a FREE table — simply seats the new party', function () {
    postContinue([
        'order_type' => 'dine_in',
        'guest_count' => 3,
        'note' => 'window seat',
    ])->assertOk()
        ->assertJsonPath('order_type', 'dine_in')
        ->assertJsonPath('guest_count', 3)
        ->assertJsonPath('note', 'window seat');

    expect(CustomerOrder::count())->toBe(1);
    $order = CustomerOrder::firstOrFail();
    expect((float) $order->subtotal)->toBe(1600.0)
        ->and($this->table->fresh()->current_order_id)->toBe($order->id);
});

// =========================================================================
//  Contract guards
// =========================================================================

it('422s without table_ids — a take-over with no tables is meaningless', function () {
    postContinue(['table_ids' => []])->assertStatus(422);
});

it('422s without items — the endpoint requires at least one line', function () {
    postContinue(['items' => []])->assertStatus(422);
});

it('422s on a foreign product SKU id that does not exist', function () {
    postContinue([
        'items' => [['product_sku_id' => (string) Str::uuid(), 'quantity' => 1]],
    ])->assertStatus(422);
});
