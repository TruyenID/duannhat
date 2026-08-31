<?php

/**
 * HQ EmployeeAdminController — Tempo-native roster under Platform SSO.
 *
 * index() now returns the LOCAL roster (users with a role assignment in the
 * resolved org). Platform owns all employee mutations, so Tempo deliberately
 * registers no invite / patch / destroy routes.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
        'slug' => 'emp-org-'.Str::random(4),
    ]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'emp-brand-'.Str::random(4),
        'is_active' => true,
    ]);
    $this->admin = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->admin->assignRole('org-admin', $this->orgId);
    $this->base = "/api/v1/hq/{$this->brand->slug}/employees";
});

// =============================================================================
//  GET /employees — local roster
// =============================================================================

it('lists local employees (users with a role in the org)', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff', $this->orgId);

    $response = $this->actingAs($this->admin)->getJson($this->base)->assertOk()
        ->assertJsonStructure(['data' => ['*' => ['id', 'name', 'email', 'is_active', 'assignments']]]);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->admin->id)->toContain($staff->id)
        ->and($response->json('all_users_access'))->toBeTrue();
});

it('does not include users from other organizations', function () {
    $otherOrg = Organization::factory()->create();
    $outsider = User::factory()->create();
    $outsider->assignRole('staff', $otherOrg->id);

    $response = $this->actingAs($this->admin)->getJson($this->base)->assertOk();

    expect(collect($response->json('data'))->pluck('id'))->not->toContain($outsider->id);
});

it('returns 403 for a caller without iam.member.view', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff', $this->orgId); // staff has no iam.member.view

    $this->actingAs($staff)->getJson($this->base)->assertForbidden();
});

it('returns 401 without auth on index', function () {
    $this->getJson($this->base)->assertUnauthorized();
});

it('does not register employee mutation routes owned by Platform', function () {
    foreach ([
        'api.v1.hq.employees.invite.branches',
        'api.v1.hq.employees.invite',
        'api.v1.hq.employees.patch',
        'api.v1.hq.employees.destroy',
    ] as $name) {
        expect(Route::getRoutes()->getByName($name))->toBeNull();
    }
});
