<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IamSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->organization = Organization::factory()->create();
    $this->orgId = $this->organization->id;

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    // org-admin caller (level 100 — can do everything)
    $this->admin = User::factory()->create();
    $this->admin->assignRole('org-admin', $this->orgId);

    // shop-manager caller (level 60 — has iam.assign, can assign below their level)
    $this->manager = User::factory()->create();
    $this->manager->assignRole('shop-manager', $this->orgId);

    $this->brandSlug = $this->brand->slug;
});

// =========================================================================
//  B1 — index: 403 without iam.member.view
// =========================================================================

it('B1: index returns 403 for a user without iam.member.view', function () {
    // shop-staff has no iam.member.view
    $shopStaff = User::factory()->create();
    $shopStaff->assignRole('shop-staff', $this->orgId);

    $this->actingAs($shopStaff)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members")
        ->assertForbidden();
});

// =========================================================================
//  B1b — index: users with iam.member.view can list members
// =========================================================================

it('B1b: shop-manager (has iam.member.view) can list members', function () {
    $shopManager = User::factory()->create();
    $shopManager->assignRole('shop-manager', $this->orgId);

    $this->actingAs($shopManager)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members")
        ->assertOk();
});

it('B1c: staff (no iam.member.view) cannot list members', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff', $this->orgId);

    $this->actingAs($staff)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members")
        ->assertForbidden();
});

it('B1d: org-manager (has iam.member.view) can list members', function () {
    $orgManager = User::factory()->create();
    $orgManager->assignRole('org-manager', $this->orgId);

    $this->actingAs($orgManager)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members")
        ->assertOk();
});

// =========================================================================
//  B2 — index: lists all members in the org
// =========================================================================

it('B2: index lists all users with any role in the org', function () {
    $staff = User::factory()->create();
    $staff->assignRole('staff', $this->orgId);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'name', 'email', 'avatar_url', 'assignments'],
            ],
        ]);

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($this->admin->id)
        ->toContain($this->manager->id)
        ->toContain($staff->id);
});

it('B2b: index does not include users from other organizations', function () {
    $otherOrg = Organization::factory()->create();
    $outsider = User::factory()->create();
    $outsider->assignRole('staff', $otherOrg->id);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members")
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->not->toContain($outsider->id);
});

// =========================================================================
//  B3 — show: 404 if user has no role in this org
// =========================================================================

it('B3: show returns 404 for a user not in this org', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$stranger->id}")
        ->assertNotFound();
});

// =========================================================================
//  B4 — show: returns member with assignments
// =========================================================================

it('B4: show returns member with correct assignments shape', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$this->manager->id}")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['id', 'name', 'email', 'avatar_url', 'assignments' => [
                '*' => ['role_slug', 'role_name', 'role_level', 'branch_id'],
            ]],
        ]);

    expect($response->json('data.id'))->toBe($this->manager->id);
    $assignment = collect($response->json('data.assignments'))->first();
    expect($assignment['role_slug'])->toBe('shop-manager');
});

// =========================================================================
//  B5 — assign: 403 without iam.assign
// =========================================================================

it('B5: assign returns 403 for user without iam.assign', function () {
    $shopStaff = User::factory()->create();
    $shopStaff->assignRole('shop-staff', $this->orgId);

    $target = User::factory()->create();

    $this->actingAs($shopStaff)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'staff',
        ])
        ->assertForbidden();
});

// =========================================================================
//  B6 — assign: level guard — cannot assign role >= caller level
// =========================================================================

it('B6: assign returns 403 when caller tries to assign a role at or above their own level', function () {
    // shop-manager (level 60) tries to assign org-admin (level 100) — blocked
    $target = User::factory()->create();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'org-admin',
        ])
        ->assertForbidden();
});

it('B6b: shop-manager cannot assign shop-manager (same level)', function () {
    $target = User::factory()->create();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-manager',
        ])
        ->assertForbidden();
});

// =========================================================================
//  B7 — assign: happy path — creates the role assignment
// =========================================================================

it('B7: assign creates role assignment and returns 201 with member data', function () {
    $target = User::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'staff',
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'data' => ['id', 'assignments'],
        ]);

    expect($response->json('data.id'))->toBe($target->id);
    $slugs = collect($response->json('data.assignments'))->pluck('role_slug');
    expect($slugs)->toContain('staff');
});

it('B7b: org-manager can assign shop-staff (below their level)', function () {
    $target = User::factory()->create();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-staff',
        ])
        ->assertCreated();
});

// =========================================================================
//  B8 — revoke: 404 if assignment doesn't exist
// =========================================================================

it('B8: revoke returns 404 when the assignment does not exist', function () {
    $target = User::factory()->create(); // no roles

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/staff")
        ->assertNotFound();
});

it('B8b: revoke returns 404 for a role not assigned in this org', function () {
    $otherOrg = Organization::factory()->create();
    $target = User::factory()->create();
    $target->assignRole('staff', $otherOrg->id);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/staff")
        ->assertNotFound();
});

// =========================================================================
//  B9 — revoke: level guard
// =========================================================================

it('B9: revoke returns 403 when caller tries to revoke a role at or above their own level', function () {
    // org-manager (level 80) tries to revoke org-admin (level 100) — blocked
    $target = User::factory()->create();
    $target->assignRole('org-admin', $this->orgId);

    $this->actingAs($this->manager)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/org-admin")
        ->assertForbidden();
});

// =========================================================================
//  B10 — revoke: happy path — removes assignment and returns 204
// =========================================================================

it('B10: revoke removes the role assignment and returns 204', function () {
    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/staff")
        ->assertNoContent();

    expect($target->fresh()->roles()->where('role_user_pivots.organization_id', $this->orgId)->exists())
        ->toBeFalse();
});

// =========================================================================
//  B8-extra — assign is idempotent: second identical call returns 201, no duplicate pivot row
// =========================================================================

it('B8-idempotent: assigning the same role twice creates exactly one pivot row', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'staff',
        ])
        ->assertCreated();

    // Second identical call — must not 422 or create a duplicate row.
    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'staff',
        ])
        ->assertCreated();

    $count = $target->roles()
        ->wherePivot('organization_id', $this->orgId)
        ->where('roles.slug', 'staff')
        ->count();
    expect($count)->toBe(1);
});

// =========================================================================
//  B5 — shop-manager can assign shop-staff to OWN branch
// =========================================================================

it('B5: shop-manager can assign shop-staff to a branch they manage', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    // shop-manager scoped to this specific branch
    $shopManager = User::factory()->create();
    $shopManager->assignRole('shop-manager', $this->orgId, $branch->id);

    $target = User::factory()->create();

    $this->actingAs($shopManager)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-staff',
            'branch_id' => $branch->id,
        ])
        ->assertCreated();
});

// =========================================================================
//  B6 — shop-manager CANNOT assign to a branch they do NOT manage
// =========================================================================

it('B6: shop-manager cannot assign shop-staff to a branch they do not manage', function () {
    $ownBranch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    // shop-manager scoped to ownBranch only
    $shopManager = User::factory()->create();
    $shopManager->assignRole('shop-manager', $this->orgId, $ownBranch->id);

    $target = User::factory()->create();

    $this->actingAs($shopManager)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-staff',
            'branch_id' => $otherBranch->id,
        ])
        ->assertForbidden();
});

it('B6b: org-admin (org-wide role) can assign to any branch', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $target = User::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-staff',
            'branch_id' => $branch->id,
        ])
        ->assertCreated();
});

// =========================================================================
//  Additional assign edge cases
// =========================================================================

it('assign: org-manager has no iam.assign permission → 403', function () {
    // org-manager (level 80) has iam.member.view but NOT iam.assign.
    // High-level roles without the specific permission must still be blocked.
    $orgManager = User::factory()->create();
    $orgManager->assignRole('org-manager', $this->orgId);

    $target = User::factory()->create();

    $this->actingAs($orgManager)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-staff',
        ])
        ->assertForbidden();
});

it('assign: missing role_slug returns 422', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [])
        ->assertUnprocessable();
});

it('assign: non-existent role_slug fails validation with 422', function () {
    // role_slug is validated via `exists:roles,slug` in AssignRoleRequest.
    $target = User::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'this-role-does-not-exist',
        ])
        ->assertUnprocessable();
});

it('assign: response does not duplicate role when assigned twice', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'staff',
        ])
        ->assertCreated();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'staff',
        ])
        ->assertCreated();

    $assignments = collect($response->json('data.assignments'));
    $staffAssignments = $assignments->filter(fn ($a) => $a['role_slug'] === 'staff');
    expect($staffAssignments)->toHaveCount(1);
});

it('assign: branch-scoped assignment shows branch_id in member response', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $target = User::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-staff',
            'branch_id' => $branch->id,
        ])
        ->assertCreated();

    $assignment = collect($response->json('data.assignments'))->first();
    expect($assignment['branch_id'])->toBe($branch->id);
});

it('assign: org-wide assignment shows null branch_id in member response', function () {
    $target = User::factory()->create();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'staff',
        ])
        ->assertCreated();

    $assignment = collect($response->json('data.assignments'))->first();
    expect($assignment['branch_id'])->toBeNull();
});

// =========================================================================
//  Additional revoke edge cases
// =========================================================================

it('revoke: non-existent role slug returns 404', function () {
    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/this-role-does-not-exist")
        ->assertNotFound();
});

it('revoke: wrong branch_id returns 404 (role assigned to branch_A, revoke with branch_B)', function () {
    $branchA = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $branchB = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $target = User::factory()->create();
    $target->assignRole('shop-staff', $this->orgId, $branchA->id);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/shop-staff?branch_id={$branchB->id}")
        ->assertNotFound();
});

it('revoke: branch-scoped role not matched when revoke called without branch_id', function () {
    // Target has shop-staff scoped to a branch; revoke call omits branch_id
    // → query looks for org-wide assignment, finds none → 404.
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $target = User::factory()->create();
    $target->assignRole('shop-staff', $this->orgId, $branch->id);

    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/shop-staff")
        ->assertNotFound();
});

it('revoke: shop-manager can revoke shop-staff from own branch', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $shopManager = User::factory()->create();
    $shopManager->assignRole('shop-manager', $this->orgId, $branch->id);

    $target = User::factory()->create();
    $target->assignRole('shop-staff', $this->orgId, $branch->id);

    $this->actingAs($shopManager)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/shop-staff?branch_id={$branch->id}")
        ->assertNoContent();
});

it('revoke: shop-manager cannot revoke from a branch they do not manage', function () {
    $ownBranch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $shopManager = User::factory()->create();
    $shopManager->assignRole('shop-manager', $this->orgId, $ownBranch->id);

    $target = User::factory()->create();
    $target->assignRole('shop-staff', $this->orgId, $otherBranch->id);

    $this->actingAs($shopManager)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/shop-staff?branch_id={$otherBranch->id}")
        ->assertForbidden();
});

it('revoke: org-manager cannot revoke (no iam.assign) → 403', function () {
    $orgManager = User::factory()->create();
    $orgManager->assignRole('org-manager', $this->orgId);

    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);

    $this->actingAs($orgManager)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/staff")
        ->assertForbidden();
});

it('revoke: only removes the target scoped row, other assignments untouched', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);             // org-wide
    $target->assignRole('shop-staff', $this->orgId, $branch->id); // branch-scoped

    // Revoke only the org-wide staff assignment
    $this->actingAs($this->admin)
        ->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/staff")
        ->assertNoContent();

    // Branch-scoped shop-staff assignment must still exist
    expect(
        $target->roles()
            ->wherePivot('organization_id', $this->orgId)
            ->wherePivot('branch_id', $branch->id)
            ->where('roles.slug', 'shop-staff')
            ->exists()
    )->toBeTrue();
});

// =========================================================================
//  index: branch-scoped users appear in org-level listing
// =========================================================================

it('index: user with only a branch-scoped role appears in org member list', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $branchUser = User::factory()->create();
    $branchUser->assignRole('shop-staff', $this->orgId, $branch->id);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members")
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($branchUser->id);
});

it('index: branch-scoped assignment shows non-null branch_id in listing', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $branchUser = User::factory()->create();
    $branchUser->assignRole('shop-staff', $this->orgId, $branch->id);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members")
        ->assertOk();

    $member = collect($response->json('data'))->firstWhere('id', $branchUser->id);
    $assignment = collect($member['assignments'])->first();
    expect($assignment['branch_id'])->toBe($branch->id);
});

// =========================================================================
//  Branch-scope × permission check interaction
// =========================================================================

it('assign: branch-only shop-manager (no org-wide role) cannot assign without branch_id', function () {
    // A shop-manager whose pivot row is branch-scoped has iam.assign only in branch context.
    // Calling assign() without branch_id → permission check uses org-only context → 403.
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $branchOnlyManager = User::factory()->create();
    $branchOnlyManager->assignRole('shop-manager', $this->orgId, $branch->id); // branch-scoped only

    $target = User::factory()->create();

    $this->actingAs($branchOnlyManager)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-staff',
            // no branch_id — attempt an org-wide assignment
        ])
        ->assertForbidden();
});

it('assign: org-wide shop-manager (no branch_id in pivot) can assign without branch_id', function () {
    // $this->manager is already an org-wide shop-manager (pivot.branch_id IS NULL).
    // This is the counterpart to the test above.
    $target = User::factory()->create();

    $this->actingAs($this->manager)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-staff',
        ])
        ->assertCreated();
});

it('assign: branch_id belonging to a different org is rejected with 422', function () {
    $otherOrg = Organization::factory()->create();
    $branchFromOtherOrg = Branch::factory()->create([
        'console_organization_id' => $otherOrg->console_organization_id,
    ]);

    $target = User::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'shop-staff',
            'branch_id' => $branchFromOtherOrg->id,
        ])
        ->assertUnprocessable();
});

// =========================================================================
//  show(): multi-org and multi-assignment isolation
// =========================================================================

it('show: user with roles in two orgs — only current org assignments returned', function () {
    $otherOrg = Organization::factory()->create();
    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);
    $target->assignRole('org-admin', $otherOrg->id); // different org — must not appear

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}")
        ->assertOk();

    $assignments = collect($response->json('data.assignments'));
    expect($assignments)->toHaveCount(1)
        ->and($assignments->first()['role_slug'])->toBe('staff');
});

it('show: user with both org-wide and branch-scoped assignments — both appear', function () {
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);                     // org-wide
    $target->assignRole('shop-staff', $this->orgId, $branch->id);  // branch-scoped

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}")
        ->assertOk();

    $assignments = collect($response->json('data.assignments'));
    expect($assignments)->toHaveCount(2);

    $slugs = $assignments->pluck('role_slug')->sort()->values()->all();
    expect($slugs)->toBe(['shop-staff', 'staff']);
});

it('show: user with multiple roles in same org — all roles appear in assignments', function () {
    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);
    $target->assignRole('shop-manager', $this->orgId); // second role same org

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}")
        ->assertOk();

    $slugs = collect($response->json('data.assignments'))->pluck('role_slug')->sort()->values()->all();
    expect($slugs)->toBe(['shop-manager', 'staff']);
});

// =========================================================================
//  Unauthenticated access → 401
// =========================================================================

it('unauthenticated: index returns 401', function () {
    $this->getJson("/api/v1/hq/{$this->brandSlug}/iam/members")
        ->assertUnauthorized();
});

it('unauthenticated: assign returns 401', function () {
    $target = User::factory()->create();
    $this->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
        'role_slug' => 'staff',
    ])->assertUnauthorized();
});

it('unauthenticated: revoke returns 401', function () {
    $target = User::factory()->create();
    $this->deleteJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/roles/staff")
        ->assertUnauthorized();
});

// =========================================================================
//  TC-MEM-DET5 — deactivate / activate member
// =========================================================================

it('DET5: org-admin can deactivate a member — is_active flips to false', function () {
    $target = User::factory()->create(['is_active' => true]);
    $target->assignRole('staff', $this->orgId);

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/deactivate")
        ->assertOk();

    expect($response->json('data.is_active'))->toBeFalse();
    expect($target->fresh()->is_active)->toBeFalse();
});

it('DET5: activate re-enables a deactivated member', function () {
    $target = User::factory()->create(['is_active' => false]);
    $target->assignRole('staff', $this->orgId);

    $response = $this->actingAs($this->admin)
        ->patchJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/activate")
        ->assertOk();

    expect($response->json('data.is_active'))->toBeTrue();
    expect($target->fresh()->is_active)->toBeTrue();
});

it('DET5: deactivate returns 403 for a caller without iam.assign', function () {
    $shopStaff = User::factory()->create();
    $shopStaff->assignRole('shop-staff', $this->orgId);

    $target = User::factory()->create(['is_active' => true]);
    $target->assignRole('staff', $this->orgId);

    $this->actingAs($shopStaff)
        ->patchJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/deactivate")
        ->assertForbidden();

    expect($target->fresh()->is_active)->toBeTrue();
});

it('DET5: a member cannot deactivate themselves', function () {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$this->admin->id}/deactivate")
        ->assertForbidden();

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

it('DET5: caller cannot deactivate a member at or above their own level', function () {
    // shop-manager (level 60) cannot deactivate org-admin (level 100).
    $this->actingAs($this->manager)
        ->patchJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$this->admin->id}/deactivate")
        ->assertForbidden();

    expect($this->admin->fresh()->is_active)->toBeTrue();
});

it('DET5: deactivate returns 404 for a non-member', function () {
    $stranger = User::factory()->create(['is_active' => true]); // no roles in this org

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$stranger->id}/deactivate")
        ->assertNotFound();
});

it('DET5: show response exposes is_active', function () {
    $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$this->manager->id}")
        ->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name', 'email', 'avatar_url', 'is_active', 'assignments']]);
});

it('DET5: deactivate is unauthenticated-guarded (401)', function () {
    $target = User::factory()->create();
    $this->patchJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/deactivate")
        ->assertUnauthorized();
});

// =========================================================================
//  TC-MEM-DET4 — reset password (Laravel password broker)
// =========================================================================

it('DET4: reset-password sends a reset link to the member', function () {
    Notification::fake();
    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/reset-password")
        ->assertOk();

    Notification::assertSentTo($target, ResetPassword::class);
});

it('DET4: reset-password returns 403 without iam.assign', function () {
    $shopStaff = User::factory()->create();
    $shopStaff->assignRole('shop-staff', $this->orgId);

    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);

    $this->actingAs($shopStaff)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/reset-password")
        ->assertForbidden();
});

it('DET4: reset-password returns 404 for a non-member', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$stranger->id}/reset-password")
        ->assertNotFound();
});

// =========================================================================
//  TC-MEM-DET7 — IAM audit trail
// =========================================================================

it('DET7: assigning a role writes an audit entry', function () {
    $target = User::factory()->create();

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'staff',
        ])->assertCreated();

    $this->assertDatabaseHas('audit_logs', [
        'auditable_id' => $target->id,
        'action' => 'iam.role_assigned',
        'user_id' => $this->admin->id,
    ]);
});

it('DET7: deactivate writes an audit entry', function () {
    $target = User::factory()->create(['is_active' => true]);
    $target->assignRole('staff', $this->orgId);

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/deactivate")
        ->assertOk();

    $this->assertDatabaseHas('audit_logs', [
        'auditable_id' => $target->id,
        'action' => 'iam.member_deactivated',
        'user_id' => $this->admin->id,
    ]);
});

it('DET7: audit endpoint returns the member IAM history with actor names', function () {
    $target = User::factory()->create(['is_active' => true]);

    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/assign", [
            'role_slug' => 'staff',
        ])->assertCreated();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/deactivate")
        ->assertOk();

    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/audit")
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'action', 'actor_name', 'metadata', 'created_at']],
        ]);

    $actions = collect($response->json('data'))->pluck('action');
    expect($actions)->toContain('iam.role_assigned')
        ->toContain('iam.member_deactivated');
    expect($response->json('data.0.actor_name'))->toBe($this->admin->name);
});

it('DET7: audit endpoint is 403 without iam.member.view', function () {
    $shopStaff = User::factory()->create();
    $shopStaff->assignRole('shop-staff', $this->orgId);

    $target = User::factory()->create();
    $target->assignRole('staff', $this->orgId);

    $this->actingAs($shopStaff)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$target->id}/audit")
        ->assertForbidden();
});

it('DET7: audit endpoint is 404 for a non-member', function () {
    $stranger = User::factory()->create();

    $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$stranger->id}/audit")
        ->assertNotFound();
});
