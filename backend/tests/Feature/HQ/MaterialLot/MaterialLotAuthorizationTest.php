<?php

/**
 * Authorization matrix tests for MaterialLotPolicy (plan-017).
 *
 * Covers DESIGN.md §"Authorization matrix" cells that the original
 * implementation left untested (REVIEW.md issue #3): role gating beyond
 * the org boundary check.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Material;
use App\Models\MaterialLot;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'authz-'.Str::random(4),
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);

    $this->warehouse = Warehouse::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->material = Material::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->lot = MaterialLot::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'material_id' => $this->material->id,
        'warehouse_id' => $this->warehouse->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}/material-lots";
});

function userWithRole(string $orgId, string $roleSlug): User
{
    $role = Role::firstOrCreate(
        ['slug' => $roleSlug],
        ['name' => ucfirst($roleSlug), 'level' => match ($roleSlug) {
            'org-admin' => 100,
            'org-manager' => 80,
            'shop-manager' => 60,
            'staff' => 30,
            'shop-staff' => 10,
            default => 0,
        }]
    );

    $user = User::factory()->create([
        'console_organization_id' => $orgId,
    ]);
    $user->assignRole($role, $orgId);

    return $user;
}

// =========================================================================
//  Dispose — only org-admin / org-manager
// =========================================================================

it('allows org-admin to dispose a lot', function () {
    $user = userWithRole($this->orgId, 'org-admin');

    $this->actingAs($user)
        ->postJson("{$this->baseUrl}/{$this->lot->id}/dispose", ['force' => true])
        ->assertOk();
});

it('allows org-manager to dispose a lot', function () {
    $user = userWithRole($this->orgId, 'org-manager');

    $this->actingAs($user)
        ->postJson("{$this->baseUrl}/{$this->lot->id}/dispose", ['force' => true])
        ->assertOk();
});

it('blocks shop-manager from dispose (HQ-only action)', function () {
    $user = userWithRole($this->orgId, 'shop-manager');

    $this->actingAs($user)
        ->postJson("{$this->baseUrl}/{$this->lot->id}/dispose", ['force' => true])
        ->assertForbidden();
});

it('blocks shop-staff from dispose', function () {
    $user = userWithRole($this->orgId, 'shop-staff');

    $this->actingAs($user)
        ->postJson("{$this->baseUrl}/{$this->lot->id}/dispose", ['force' => true])
        ->assertForbidden();
});

// =========================================================================
//  Quarantine — org-admin / org-manager / shop-manager (own warehouse)
// =========================================================================

it('allows shop-manager to quarantine', function () {
    $user = userWithRole($this->orgId, 'shop-manager');

    $this->actingAs($user)
        ->postJson("{$this->baseUrl}/{$this->lot->id}/quarantine", ['reason' => 'lab hold'])
        ->assertOk();
});

it('blocks shop-staff from quarantine', function () {
    $user = userWithRole($this->orgId, 'shop-staff');

    $this->actingAs($user)
        ->postJson("{$this->baseUrl}/{$this->lot->id}/quarantine", ['reason' => 'lab hold'])
        ->assertForbidden();
});

it('blocks plain staff from quarantine', function () {
    $user = userWithRole($this->orgId, 'staff');

    $this->actingAs($user)
        ->postJson("{$this->baseUrl}/{$this->lot->id}/quarantine", ['reason' => 'lab hold'])
        ->assertForbidden();
});

// =========================================================================
//  Cross-org boundary
// =========================================================================

it('blocks any role from accessing a cross-org lot', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);

    $stranger = userWithRole($otherOrgId, 'org-admin');

    $this->actingAs($stranger)
        ->postJson("{$this->baseUrl}/{$this->lot->id}/dispose", ['force' => true])
        ->assertForbidden();
});
