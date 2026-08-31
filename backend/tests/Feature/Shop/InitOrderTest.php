<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
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
        'slug' => 'init-order-shop',
        'is_active' => true,
    ]);

    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );

    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);

    $zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
        'current_order_id' => null,
    ]);

    // Create a spot order (no tables, no guest_count)
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $this->order = CustomerOrder::first();
});

// =========================================================================
//  Happy path
// =========================================================================

it('assigns tables and guest_count via init on order with no tables', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [$this->table->id],
            'guest_count' => 4,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.guest_count', 4);

    expect($this->table->fresh()->current_order_id)->toBe($this->order->id);
    expect($this->table->fresh()->status->value)->toBe('occupied');

    // Regression: init must back-fill the denormalized primary-table pointer
    // (mirrors create()) so continue-table + table_id filters resolve the order.
    expect($this->order->fresh()->table_id)->toBe($this->table->id);
});

it('assigns tables without guest_count via init', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [$this->table->id],
        ])
        ->assertSuccessful();

    expect($this->table->fresh()->current_order_id)->toBe($this->order->id);
    expect($this->order->fresh()->guest_count)->toBeNull();
});

// =========================================================================
//  First-write-wins semantics
// =========================================================================

it('ignores table_ids when order already has tables assigned', function () {
    // First: assign table via init
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [$this->table->id],
        ])
        ->assertSuccessful();

    // Create a new free table
    $zone = Zone::factory()->for($this->shop, 'branch')->create(['organization_id' => $this->orgId]);
    $newTable = Table::factory()->for($this->shop, 'branch')->for($zone, 'zone')->create([
        'organization_id' => $this->orgId,
        'status' => 'free',
        'current_order_id' => null,
    ]);

    // Second init: should NOT assign the new table
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [$newTable->id],
        ])
        ->assertSuccessful();

    expect($newTable->fresh()->current_order_id)->toBeNull();
    expect($this->table->fresh()->current_order_id)->toBe($this->order->id);
});

it('ignores guest_count when DB value is already set', function () {
    $this->order->update(['guest_count' => 4]);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'guest_count' => 8,
        ])
        ->assertSuccessful();

    expect((int) $this->order->fresh()->guest_count)->toBe(4);
});

it('skips guest_count when request value is null', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'guest_count' => null,
        ])
        ->assertSuccessful();

    expect($this->order->fresh()->guest_count)->toBeNull();
});

// =========================================================================
//  Authorization
// =========================================================================

it('returns 403 for init on order from different org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);

    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'slug' => 'other-org-init-shop',
        'is_active' => true,
    ]);

    $otherUser = User::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherUser->assignRole($this->managerRole, $otherOrgId);

    // Access order from org-A through org-B's shop URL → 403
    $this->actingAs($otherUser)
        ->putJson("/api/v1/shops/{$otherShop->slug}/orders/{$this->order->id}/init", [
            'guest_count' => 4,
        ])
        ->assertForbidden();
});

it('returns 200 for init on own org order', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'guest_count' => 2,
        ])
        ->assertSuccessful();
});

// =========================================================================
//  Error handling
// =========================================================================

it('returns 409 for init on checkout order', function () {
    $this->order->update(['status' => 'checkout']);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'guest_count' => 4,
        ])
        ->assertStatus(409);
});

it('returns 422 for init with invalid table_ids', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [(string) Str::uuid()],
        ])
        ->assertUnprocessable();
});

it('returns 422 for init with guest_count=-1', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'guest_count' => -1,
        ])
        ->assertUnprocessable();
});

// =========================================================================
//  Side effects
// =========================================================================

it('creates audit log entry on init', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}/init", [
            'table_ids' => [$this->table->id],
        ])
        ->assertSuccessful();

    expect(AuditLog::where('auditable_id', $this->order->id)->where('action', 'init_updated')->exists())->toBeTrue();
});
