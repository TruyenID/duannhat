<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
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
        'slug' => 'update-order-shop',
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
    ]);

    // Create an open order
    $this->actingAs($this->manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders")
        ->assertCreated();

    $this->order = CustomerOrder::first();
});

// =========================================================================
//  Happy path
// =========================================================================

it('updates guest_count and note on an open order', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'guest_count' => 6,
            'note' => 'VIP table',
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.guest_count', 6)
        ->assertJsonPath('data.note', 'VIP table');
});

it('overwrites guest_count with last-write-wins', function () {
    $this->order->update(['guest_count' => 4]);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'guest_count' => 8,
        ])
        ->assertSuccessful()
        ->assertJsonPath('data.guest_count', 8);

    expect((int) $this->order->fresh()->guest_count)->toBe(8);
});

// =========================================================================
//  Validation
// =========================================================================

it('rejects update with invalid order_type', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'order_type' => 'nonexistent',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['order_type']);
});

// =========================================================================
//  Authorization
// =========================================================================

it('returns 403 for update on order from different org', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);

    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'slug' => 'other-org-update-shop',
        'is_active' => true,
    ]);

    $otherUser = User::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherUser->assignRole($this->managerRole, $otherOrgId);

    // Access order from org-A through org-B's shop URL → 403
    $this->actingAs($otherUser)
        ->putJson("/api/v1/shops/{$otherShop->slug}/orders/{$this->order->id}", [
            'guest_count' => 10,
        ])
        ->assertForbidden();
});

// =========================================================================
//  Error handling
// =========================================================================

it('returns 409 for update on checkout order', function () {
    $this->order->update(['status' => 'checkout']);

    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'guest_count' => 10,
        ])
        ->assertStatus(409);
});

// =========================================================================
//  Side effects
// =========================================================================

it('creates audit log entry on update', function () {
    $this->actingAs($this->manager)
        ->putJson("/api/v1/shops/{$this->shop->slug}/orders/{$this->order->id}", [
            'note' => 'Updated note',
        ])
        ->assertSuccessful();

    expect(AuditLog::where('auditable_id', $this->order->id)->where('action', 'updated')->exists())->toBeTrue();
});
