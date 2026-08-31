<?php

// plan-042 — referential delete-guards: an entity referenced by an OPEN order
// (or, for denominations, an open till shift) cannot be deleted (409 + code).

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Table;
use App\Models\Till;
use App\Models\User;
use App\Models\Zone;
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
        'slug' => 'test-shop',
        'is_active' => true,
    ]);
    $this->managerRole = Role::firstOrCreate(
        ['slug' => 'org-manager'],
        ['name' => 'Org Manager', 'level' => 50],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($this->managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);
});

// ─── Table ───────────────────────────────────────────────────────────────────

it('blocks deleting a table referenced by an open order (409)', function () {
    $table = Table::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
    CustomerOrder::factory()->open()->create(['branch_id' => $this->shop->id, 'table_id' => $table->id]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/tables/{$table->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'TABLE_DELETE_BLOCKED_OPEN_ORDER');

    expect(Table::withTrashed()->find($table->id)->deleted_at)->toBeNull();
});

it('allows deleting a table with no open order (204)', function () {
    $table = Table::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
    CustomerOrder::factory()->closed()->create(['branch_id' => $this->shop->id, 'table_id' => $table->id]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/tables/{$table->id}")
        ->assertNoContent();

    expect(Table::withTrashed()->find($table->id)->deleted_at)->not->toBeNull();
});

// ─── Customer ──────────────────────────────────────────────────────────────

it('blocks deleting a customer with an open order (409)', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->shop->id,
    ]);
    CustomerOrder::factory()->open()->create(['branch_id' => $this->shop->id, 'customer_id' => $customer->id]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/customers/{$customer->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'CUSTOMER_DELETE_BLOCKED_OPEN_ORDER');
});

it('allows deleting a customer whose orders are all closed (204)', function () {
    $customer = Customer::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'branch_id' => $this->shop->id,
    ]);
    CustomerOrder::factory()->closed()->create(['branch_id' => $this->shop->id, 'customer_id' => $customer->id]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/customers/{$customer->id}")
        ->assertNoContent();
});

// ─── Zone ────────────────────────────────────────────────────────────────────

it('blocks deleting a zone when a table in it has an open order (409)', function () {
    $zone = Zone::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id]);
    $table = Table::factory()->create(['organization_id' => $this->orgId, 'branch_id' => $this->shop->id, 'zone_id' => $zone->id]);
    CustomerOrder::factory()->open()->create(['branch_id' => $this->shop->id, 'table_id' => $table->id]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/zones/{$zone->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'ZONE_DELETE_BLOCKED_OPEN_ORDER');

    expect(Zone::withTrashed()->find($zone->id)->deleted_at)->toBeNull();
    expect(Table::withTrashed()->find($table->id)->deleted_at)->toBeNull();
});

// ─── Denomination ────────────────────────────────────────────────────────────

it('blocks deleting a denomination while a shift is open in the org (409)', function () {
    $denom = Denomination::factory()->create(['organization_id' => $this->orgId]);
    Till::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->shop->id,
        'current_session_id' => (string) Str::uuid(),
    ]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/denominations/{$denom->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'DENOMINATION_DELETE_BLOCKED_OPEN_SHIFT');
});

it('allows deleting a denomination when an open shift is in a different org', function () {
    $denom = Denomination::factory()->create(['organization_id' => $this->orgId]);
    $otherOrg = Organization::factory()->create();
    Till::factory()->create([
        'organization_id' => $otherOrg->id,
        'current_session_id' => (string) Str::uuid(),
    ]);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/shops/{$this->shop->slug}/denominations/{$denom->id}")
        ->assertNoContent();
});
