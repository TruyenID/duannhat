<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TillSession;
use App\Models\User;
use App\Policies\ShopTillTrackingPolicy;
use Illuminate\Support\Str;

/*
 * Plan 036 — Unit tests for ShopTillTrackingPolicy.
 *
 * The policy reads the resolved org + branch ids off the current Request's
 * attributes, mirroring how ResolveShopFromSlug stamps them in production.
 * Tests fake that wiring via Request::merge → Request::setUserResolver, but
 * because the policy uses request()->attributes->get(...), the simplest path
 * is to bind a synthetic Request into the container.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    $this->otherOrgId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    Organization::factory()->create([
        'id' => $this->otherOrgId,
        'console_organization_id' => $this->otherOrgId,
    ]);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    // Roles.
    Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);
    Role::firstOrCreate(['slug' => 'org-manager'], ['name' => 'Org Manager', 'level' => 80]);
    Role::firstOrCreate(['slug' => 'org-admin'], ['name' => 'Org Admin', 'level' => 100]);
    Role::firstOrCreate(['slug' => 'staff'], ['name' => 'Staff', 'level' => 30]);

    // Stamp request attributes so policy resolves org + branch.
    request()->attributes->set('organization_id', $this->orgId);
    request()->attributes->set('shop_id', $this->branch->id);
});

it('grants viewDashboard to shop-manager assigned to this branch', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $user->assignRole('shop-manager', $this->orgId, $this->branch->id);

    expect((new ShopTillTrackingPolicy)->viewDashboard($user))->toBeTrue();
});

it('grants viewDashboard to org-admin', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $user->assignRole('org-admin', $this->orgId);

    expect((new ShopTillTrackingPolicy)->viewDashboard($user))->toBeTrue();
});

it('grants viewDashboard to org-manager', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $user->assignRole('org-manager', $this->orgId);

    expect((new ShopTillTrackingPolicy)->viewDashboard($user))->toBeTrue();
});

it('denies viewDashboard to plain staff', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    $user->assignRole('staff', $this->orgId);

    expect((new ShopTillTrackingPolicy)->viewDashboard($user))->toBeFalse();
});

it('denies viewDashboard to a manager of a different org', function () {
    $user = User::factory()->create(['console_organization_id' => $this->otherOrgId]);
    $user->assignRole('org-admin', $this->otherOrgId);

    expect((new ShopTillTrackingPolicy)->viewDashboard($user))->toBeFalse();
});

it('grants viewSession only when session belongs to the resolved org', function () {
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $manager->assignRole('shop-manager', $this->orgId, $this->branch->id);

    $sameOrgSession = TillSession::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    $otherOrgSession = TillSession::factory()->create([
        'organization_id' => $this->otherOrgId,
    ]);

    $policy = new ShopTillTrackingPolicy;
    expect($policy->viewSession($manager, $sameOrgSession))->toBeTrue();
    expect($policy->viewSession($manager, $otherOrgSession))->toBeFalse();
});

it('mirrors viewSession behaviour for printZReport', function () {
    $manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $manager->assignRole('shop-manager', $this->orgId, $this->branch->id);

    $session = TillSession::factory()->settled()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    expect((new ShopTillTrackingPolicy)->printZReport($manager, $session))->toBeTrue();

    $staff = User::factory()->create(['console_organization_id' => $this->orgId]);
    $staff->assignRole('staff', $this->orgId);
    expect((new ShopTillTrackingPolicy)->printZReport($staff, $session))->toBeFalse();
});
