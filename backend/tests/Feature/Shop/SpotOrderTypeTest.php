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
        'slug' => 'spot-type-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

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
        'selling_price' => 1200,
        'is_active' => true,
    ]);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table1 = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
        'current_order_id' => null,
    ]);

    $this->table2 = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
        'current_order_id' => null,
    ]);
});

it('allows adding items to a spot order without tables', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $order = CustomerOrder::first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/items", [
            'items' => [
                ['product_sku_id' => $this->sku->id, 'quantity' => 2],
            ],
        ])
        ->assertCreated();

    expect($order->fresh()->items)->toHaveCount(1);
});

it('voids a spot order without tables cleanly', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $order = CustomerOrder::first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Customer changed mind',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.status', 'voided');
});

it('voids an order with tables and releases both tables', function () {
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders", [
            'table_ids' => [$this->table1->id, $this->table2->id],
        ])
        ->assertCreated();

    $order = CustomerOrder::first();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/void", [
            'void_reason' => 'Mistake',
        ])
        ->assertSuccessful();

    expect($this->table1->fresh()->current_order_id)->toBeNull();
    expect($this->table1->fresh()->status->value)->toBe('free');
    expect($this->table2->fresh()->current_order_id)->toBeNull();
    expect($this->table2->fresh()->status->value)->toBe('free');
});
