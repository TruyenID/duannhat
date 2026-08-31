<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

/*
 * Plan 030 — GET /api/v1/pos/staff
 *
 * Returns active org users for the "Người mở ca" dropdown on /shift/open.
 * Branch-scoped via the shop's organization (resolved by ResolvePosShop).
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
        'slug' => 'staff-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-staff'],
        ['name' => 'Org Staff', 'level' => 10],
    );

    // Three cashiers in the org + one outside-org user that should NOT
    // appear in the response.
    $this->cashierA = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Hai',
        'email' => 'hai@famgia.com',
    ]);
    $this->cashierA->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashierA, $this->orgId);

    $this->cashierB = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Tanaka Yuki',
        'email' => 'tanaka@famgia.com',
    ]);
    $this->cashierB->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashierB, $this->orgId);

    $this->cashierInactive = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Suzuki Ken',
        'email' => 'suzuki@famgia.com',
        'is_active' => false, // inactive — must be filtered out
    ]);
    $this->cashierInactive->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashierInactive, $this->orgId);

    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $otherOrgId,
        'console_organization_id' => $otherOrgId,
    ]);
    $this->outsider = User::factory()->create([
        'console_organization_id' => $otherOrgId,
        'name' => 'Outsider',
        'email' => 'out@famgia.com',
    ]);
    $this->outsider->assignRole($role, $otherOrgId);
    grantOrgAccess($this->outsider, $otherOrgId);
});

it('returns the active staff of the resolved shop organization', function () {
    $response = $this->actingAs($this->cashierA)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/staff')
        ->assertOk()
        ->json('data');

    $names = collect($response)->pluck('name')->all();
    expect($names)->toContain('Hai');
    expect($names)->toContain('Tanaka Yuki');
    expect($names)->not->toContain('Suzuki Ken'); // inactive
    expect($names)->not->toContain('Outsider');   // different org
});

it('every entry has the shape pos-web expects', function () {
    $response = $this->actingAs($this->cashierA)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/staff')
        ->assertOk()
        ->json('data');

    foreach ($response as $row) {
        expect($row)->toHaveKeys(['id', 'name', 'email', 'avatar_url']);
    }
});

it('requires authentication (sso.auth)', function () {
    $this->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/staff')
        ->assertStatus(401);
});

it('refuses cross-organization access via X-Shop-Slug (403 from ResolvePosShop)', function () {
    $this->actingAs($this->outsider)
        ->withHeader('X-Shop-Slug', $this->shop->slug)
        ->getJson('/api/v1/pos/staff')
        ->assertStatus(403);
});
