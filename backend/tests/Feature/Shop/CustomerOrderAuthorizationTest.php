<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
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
        'slug' => 'auth-test-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $this->customer = Customer::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
        'first_name' => 'Auth',
        'last_name' => 'Customer',
    ]);

    $pt = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $pt->id,
    ]);

    $this->sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'is_active' => true,
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);
});

// =========================================================================
//  Unauthenticated — 401
// =========================================================================

it('returns 401 for unauthenticated GET /orders', function () {
    $this->getJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertUnauthorized();
});

it('returns 401 for unauthenticated POST /orders', function () {
    $this->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
        'order_type' => 'dine_in',
        'table_ids' => [$this->table->id],
    ])->assertUnauthorized();
});

it('returns 401 for unauthenticated POST /checkout', function () {
    $order = createAuthOrder();

    $this->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/checkout")
        ->assertUnauthorized();
});

// =========================================================================
//  Cross-organization — 403
// =========================================================================

it('returns 403 when user from org-B accesses order created by org-A', function () {
    // Order lives in org-A (test()->orgId)
    $order = createAuthOrder();

    // Create org-B user
    $orgBId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgBId,
        'console_organization_id' => $orgBId,
    ]);
    $orgBUser = User::factory()->create(['console_organization_id' => $orgBId]);
    $orgBUser->assignRole($this->managerRole, $orgBId);

    // org-B user must have a shop in org-B to build a valid slug-route
    $orgBBrand = Brand::factory()->create(['console_organization_id' => $orgBId]);
    $orgBShop = Branch::factory()->create([
        'console_organization_id' => $orgBId,
        'console_brand_id' => $orgBBrand->console_brand_id,
        'slug' => 'org-b-shop',
        'is_active' => true,
    ]);

    // Attempting to read an order that belongs to org-A through org-B's shop → 403
    $this->actingAs($orgBUser)
        ->getJson("/api/v1/shops/{$orgBShop->slug}/orders/{$order->id}")
        ->assertForbidden();
});

// =========================================================================
//  Authorized — success
// =========================================================================

it('authorized user can list orders and receives 200', function () {
    $this->actingAs($this->manager)
        ->getJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertOk()
        ->assertJsonStructure(['data', 'meta']);
});

it('authorized user can create an order and receives 201', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'order_type' => 'dine_in',
            'customer_id' => $this->customer->id,
            'table_ids' => [$this->table->id],
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'open');
});

// =========================================================================
//  Helper
// =========================================================================

function createAuthOrder(): CustomerOrder
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-AUTH-'.Str::random(4),
        'order_type' => 'dine_in',
        'status' => 'open',
        'opened_at' => now(),
        'subtotal' => 1000,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 1000,
        'paid_amount' => 0,
        'total_tip' => 0,
        'created_by_id' => test()->manager->id,
        'customer_id' => test()->customer->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    Table::where('id', test()->table->id)->update([
        'current_order_id' => $order->id,
        'status' => 'occupied',
    ]);

    $order->items()->create([
        'product_sku_id' => test()->sku->id,
        'quantity' => 2,
        'unit_price' => 500,
        'original_unit_price' => 500,
        'subtotal' => 1000,
        'status' => 'pending',
        'tax_rate' => 0,
    ]);

    return $order->load('items');
}
