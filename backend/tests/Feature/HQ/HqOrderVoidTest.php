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
use Illuminate\Support\Str;

/**
 * HQ cross-branch void. The HQ order surface used to be read-only; brand
 * admins can now void an order from headquarters through the same teardown
 * the shop side uses. Because the order binds by its global id, every
 * single-order HQ endpoint must be brand-scoped — see BrandScopeIsolationTest
 * for the wider regression this mirrors.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    // Two brands in the SAME organization — the isolation boundary under test.
    $this->brandA = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);
    $this->brandB = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->actingAs($this->manager);
});

it('voids an order through its own brand route', function () {
    $order = hqOrder($this->brandA);

    $this->postJson("/api/v1/hq/{$this->brandA->slug}/orders/{$order->id}/void", [
        'void_reason' => 'Het nguyen lieu',
    ])->assertOk();

    $order->refresh();

    expect($order->status->value)->toBe('voided')
        ->and($order->void_reason)->toBe('Het nguyen lieu')
        ->and($order->voided_at)->not->toBeNull();

    expect($order->items()->pluck('void_reason')->all())
        ->each->toBe('Het nguyen lieu');
});

it('releases the table held by the voided order', function () {
    $order = hqOrder($this->brandA);
    $table = Table::where('current_order_id', $order->id)->firstOrFail();

    $this->postJson("/api/v1/hq/{$this->brandA->slug}/orders/{$order->id}/void", [
        'void_reason' => 'Nham ban',
    ])->assertOk();

    $table->refresh();

    expect($table->current_order_id)->toBeNull()
        ->and($table->status->value)->toBe('free');
});

// ── Brand isolation ─────────────────────────────────────────────────────────

it('returns 404 when voiding an order through a sibling brand route', function () {
    $orderB = hqOrder($this->brandB);

    $this->postJson("/api/v1/hq/{$this->brandA->slug}/orders/{$orderB->id}/void", [
        'void_reason' => 'cross-brand write attempt',
    ])->assertNotFound();

    // The sibling brand's order is untouched.
    expect($orderB->refresh()->status->value)->toBe('open');
});

it('returns 404 when reading an order through a sibling brand route', function () {
    $orderB = hqOrder($this->brandB);

    $this->getJson("/api/v1/hq/{$this->brandA->slug}/orders/{$orderB->id}")
        ->assertNotFound();
});

// ── Guards ──────────────────────────────────────────────────────────────────

it('rejects a void with no reason', function (mixed $reason) {
    $order = hqOrder($this->brandA);

    $this->postJson(
        "/api/v1/hq/{$this->brandA->slug}/orders/{$order->id}/void",
        $reason === null ? [] : ['void_reason' => $reason],
    )->assertStatus(422);

    expect($order->refresh()->status->value)->toBe('open');
})->with([
    'omitted' => null,
    'empty string' => '',
]);

it('returns 409 when voiding a closed order', function () {
    $order = hqOrder($this->brandA);
    $order->update([
        'status' => 'closed',
        'checkout_at' => now()->subMinutes(10),
        'closed_at' => now(),
    ]);

    $this->postJson("/api/v1/hq/{$this->brandA->slug}/orders/{$order->id}/void", [
        'void_reason' => 'Too late',
    ])->assertStatus(409);

    expect($order->refresh()->status->value)->toBe('closed');
});

function hqOrder(Brand $brand): CustomerOrder
{
    $orgId = test()->orgId;

    $branch = Branch::factory()->create([
        'console_organization_id' => $orgId,
        'console_brand_id' => $brand->console_brand_id,
        'is_active' => true,
    ]);

    $productType = ProductType::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
    ]);

    $product = Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'product_type_id' => $productType->id,
    ]);

    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-H'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 2400,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 2400,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $branch->id,
        'brand_id' => $brand->id,
        'organization_id' => $orgId,
    ]);

    $order->items()->create([
        'product_sku_id' => $sku->id,
        'quantity' => 2,
        'unit_price' => 1200,
        'original_unit_price' => 1200,
        'subtotal' => 2400,
        'status' => 'pending',
        'tax_rate' => 0,
    ]);

    $zone = Zone::factory()->for($branch, 'branch')->create([
        'organization_id' => $orgId,
    ]);

    Table::factory()->for($branch, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $orgId,
        'status' => 'occupied',
        'current_order_id' => $order->id,
    ]);

    return $order->refresh();
}
