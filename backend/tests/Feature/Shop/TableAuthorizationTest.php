<?php

use App\Models\Branch;
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

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'main-shop',
        'is_active' => true,
    ]);

    $this->zone = Zone::factory()->for($this->shop, 'branch')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->table = Table::factory()->for($this->shop, 'branch')->for($this->zone, 'zone')->create([
        'organization_id' => $this->orgId,
    ]);

    $this->managerRole = Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 50]);
    $this->staffRole = Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'level' => 10]);
});

// =========================================================================
//  Unauthenticated
// =========================================================================

it('returns 401 for unauthenticated table list', function () {
    $this->getJson("/api/v1/shops/{$this->shop->slug}/tables")->assertUnauthorized();
});

// =========================================================================
//  Cross-organization
// =========================================================================

it('returns 403 for a user from a different organization', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $otherUser = User::factory()->create(['console_organization_id' => $otherOrgId]);

    $this->actingAs($otherUser)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tables")
        ->assertForbidden();
});

// =========================================================================
//  Role gating
// =========================================================================

it('lets a Shop Staff list tables', function () {
    $staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $staff->assignRole($this->staffRole, $this->orgId);

    $this->actingAs($staff)
        ->getJson("/api/v1/shops/{$this->shop->slug}/tables")
        ->assertOk();
});

it('lets a Shop Staff change runtime status', function () {
    $staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $staff->assignRole($this->staffRole, $this->orgId);

    $this->actingAs($staff)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}/status", [
            'status' => 'occupied',
        ])
        ->assertOk();
});

it('blocks a Shop Staff from creating a table', function () {
    $staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $staff->assignRole($this->staffRole, $this->orgId);

    $this->actingAs($staff)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables", [
            'code' => 'T-01',
            'seat_count' => 4,
            'zone_id' => $this->zone->id,
        ])
        ->assertForbidden();
});

it('blocks a Shop Staff from regenerating QR', function () {
    $staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $staff->assignRole($this->staffRole, $this->orgId);

    $this->actingAs($staff)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables/{$this->table->id}/regenerate-qr")
        ->assertForbidden();
});

it('lets a Shop Manager create a table', function () {
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $manager->assignRole($this->managerRole, $this->orgId);

    $this->actingAs($manager)
        ->postJson("/api/v1/shops/{$this->shop->slug}/tables", [
            'code' => 'T-01',
            'seat_count' => 4,
            'zone_id' => $this->zone->id,
        ])
        ->assertCreated();
});
