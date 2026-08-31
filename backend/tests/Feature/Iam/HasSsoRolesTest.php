<?php

/**
 * Unit tests for HasSsoRoles trait.
 *
 * These cover the core RBAC scoping logic directly — no HTTP layer involved.
 * Tests correspond to the A1–A7 matrix in plan-007 TESTS (archived, removed from tree #2188 — git history).
 *
 * All assignments use the User model (which uses the trait). Tests run against
 * a real (sqlite/mysql) DB via RefreshDatabase so pivot queries work correctly.
 */

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\IamSeeder;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->org = Organization::factory()->create();
    $this->orgId = $this->org->id;

    $this->otherOrg = Organization::factory()->create();

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->org->console_organization_id,
    ]);
    $this->branchId = $this->branch->id;

    $this->user = User::factory()->create();
});

// =============================================================================
// A1 — assignRole is idempotent (no duplicate pivot rows)
// =============================================================================

it('A1: assignRole twice creates exactly one pivot row', function () {
    $this->user->assignRole('org-admin', $this->orgId);
    $this->user->assignRole('org-admin', $this->orgId);

    $count = $this->user->roles()
        ->wherePivot('organization_id', $this->orgId)
        ->wherePivotNull('branch_id')
        ->where('roles.slug', 'org-admin')
        ->count();

    expect($count)->toBe(1);
});

it('A1b: same role, different scopes creates separate rows', function () {
    $this->user->assignRole('shop-manager', $this->orgId);                   // org-wide
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId); // branch-scoped

    $count = $this->user->roles()
        ->wherePivot('organization_id', $this->orgId)
        ->where('roles.slug', 'shop-manager')
        ->count();

    expect($count)->toBe(2);
});

it('A1c: assignRole with no scope (global) is idempotent', function () {
    $this->user->assignRole('staff');
    $this->user->assignRole('staff');

    $count = $this->user->roles()
        ->wherePivotNull('organization_id')
        ->wherePivotNull('branch_id')
        ->where('roles.slug', 'staff')
        ->count();

    expect($count)->toBe(1);
});

// =============================================================================
// A2 — getRolesForContext(null, null) returns only global assignments
// =============================================================================

it('A2: getRolesForContext with no args returns only global-scope roles', function () {
    $this->user->assignRole('staff');                          // global (both pivot cols NULL)
    $this->user->assignRole('org-admin', $this->orgId);       // org-scoped

    $roles = $this->user->getRolesForContext(null, null);

    expect($roles->pluck('slug')->all())->toBe(['staff'])
        ->and($roles)->toHaveCount(1);
});

it('A2b: getRolesForContext returns empty when user has only org-scoped roles', function () {
    $this->user->assignRole('org-admin', $this->orgId);

    $roles = $this->user->getRolesForContext(null, null);

    expect($roles)->toHaveCount(0);
});

// =============================================================================
// A3 — getRolesForContext(orgId) includes global + org-wide, excludes branch
// =============================================================================

it('A3: org context returns global + org-wide roles', function () {
    $this->user->assignRole('staff');                                          // global
    $this->user->assignRole('org-manager', $this->orgId);                     // org-wide
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId);  // branch-scoped

    $roles = $this->user->getRolesForContext($this->orgId);
    $slugs = $roles->pluck('slug')->sort()->values()->all();

    expect($slugs)->toBe(['org-manager', 'staff'])
        ->and($roles)->toHaveCount(2);
});

it('A3b: org context does not bleed into a different org', function () {
    $this->user->assignRole('org-admin', $this->otherOrg->id);

    $roles = $this->user->getRolesForContext($this->orgId);

    expect($roles)->toHaveCount(0);
});

// =============================================================================
// A4 — getRolesForContext(orgId, branchId) includes global + org-wide + branch
// =============================================================================

it('A4: branch context returns global + org-wide + branch-scoped roles', function () {
    $this->user->assignRole('staff');                                          // global
    $this->user->assignRole('org-manager', $this->orgId);                     // org-wide
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId);  // branch-scoped

    $roles = $this->user->getRolesForContext($this->orgId, $this->branchId);
    $slugs = $roles->pluck('slug')->sort()->values()->all();

    expect($slugs)->toBe(['org-manager', 'shop-manager', 'staff'])
        ->and($roles)->toHaveCount(3);
});

it('A4b: branch context does NOT include roles from a different branch', function () {
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->org->console_organization_id,
    ]);

    $this->user->assignRole('shop-manager', $this->orgId, $otherBranch->id);  // different branch

    $roles = $this->user->getRolesForContext($this->orgId, $this->branchId);

    expect($roles)->toHaveCount(0);
});

// =============================================================================
// A5 — hasRoleInContext respects scope boundary
// =============================================================================

it('A5: branch-scoped role is not visible in org-only context', function () {
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId);

    // Org-only context → shop-manager is NOT found (it's branch-scoped, not org-wide)
    expect($this->user->hasRoleInContext('shop-manager', $this->orgId))->toBeFalse();
});

it('A5b: branch-scoped role IS visible in matching branch context', function () {
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId);

    expect($this->user->hasRoleInContext('shop-manager', $this->orgId, $this->branchId))->toBeTrue();
});

it('A5c: org-wide role is visible in both org and branch contexts', function () {
    $this->user->assignRole('org-manager', $this->orgId);

    expect($this->user->hasRoleInContext('org-manager', $this->orgId))->toBeTrue()
        ->and($this->user->hasRoleInContext('org-manager', $this->orgId, $this->branchId))->toBeTrue();
});

// =============================================================================
// A6 — hasPermission delegates to the role's permission set
// =============================================================================

it('A6: hasPermission returns true when role has the permission', function () {
    $this->user->assignRole('staff', $this->orgId);

    expect($this->user->hasPermission('catalog.view', $this->orgId))->toBeTrue();
});

it('A6b: hasPermission returns false for a permission the role does not have', function () {
    $this->user->assignRole('staff', $this->orgId);

    // staff does NOT have catalog.approve per the IamSeeder matrix
    expect($this->user->hasPermission('catalog.approve', $this->orgId))->toBeFalse();
});

it('A6c: hasPermission returns false when user has no roles in org', function () {
    // user has no assignments at all
    expect($this->user->hasPermission('catalog.view', $this->orgId))->toBeFalse();
});

it('A6d: org-admin has all 33 permissions', function () {
    $this->user->assignRole('org-admin', $this->orgId);

    $allSlugs = Permission::pluck('slug')->toArray();

    foreach ($allSlugs as $slug) {
        expect($this->user->hasPermission($slug, $this->orgId))
            ->toBeTrue("org-admin should have [{$slug}]");
    }
});

it('A6e: branch-scoped shop-manager has permissions only in branch context', function () {
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId);

    // Checking at org level (no branch) — shop-manager row not in scope
    expect($this->user->hasPermission('inventory.view', $this->orgId))->toBeFalse();

    // Checking at branch level — shop-manager row IS in scope
    expect($this->user->hasPermission('inventory.view', $this->orgId, $this->branchId))->toBeTrue();
});

it('A6f: recognizes Platform tempo role slugs as their canonical templates', function (
    string $platformSlug,
    string $templateSlug,
) {
    $platformRole = Role::create([
        'slug' => $platformSlug,
        'name' => $platformSlug,
        'level' => 50,
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($platformRole, $this->orgId);

    expect($this->user->hasRoleInContext($templateSlug, $this->orgId))->toBeTrue();
})->with([
    'owner is org admin' => ['tempo-owner', 'org-admin'],
    'admin is org admin' => ['tempo-admin', 'org-admin'],
    'manager is shop manager' => ['tempo-manager', 'shop-manager'],
    'staff is shop staff' => ['tempo-staff', 'shop-staff'],
]);

it('A6g: does not recognize a Platform role outside its organization or branch scope', function () {
    $platformRole = Role::create([
        'slug' => 'tempo-manager',
        'name' => 'Tempo Manager',
        'level' => 60,
        'console_organization_id' => $this->orgId,
    ]);
    $this->user->assignRole($platformRole, $this->orgId, $this->branchId);

    expect($this->user->hasRoleInContext('shop-manager', $this->orgId))->toBeFalse()
        ->and($this->user->hasRoleInContext('shop-manager', $this->otherOrg->id, $this->branchId))->toBeFalse()
        ->and($this->user->hasRoleInContext('shop-manager', $this->orgId, $this->branchId))->toBeTrue();
});

// =============================================================================
// A7 — removeRole removes only the specific scoped row
// =============================================================================

it('A7: removeRole removes only the matching scoped row, leaves others intact', function () {
    $this->user->assignRole('staff');                                          // global row
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId);  // branch-scoped row

    $shopManagerRole = Role::where('slug', 'shop-manager')->first();
    $this->user->removeRole($shopManagerRole, $this->orgId, $this->branchId);

    // Global 'staff' row must still exist
    expect(
        $this->user->roles()
            ->wherePivotNull('organization_id')
            ->wherePivotNull('branch_id')
            ->where('roles.slug', 'staff')
            ->exists()
    )->toBeTrue();

    // Branch-scoped 'shop-manager' row must be gone
    expect(
        $this->user->roles()
            ->wherePivot('organization_id', $this->orgId)
            ->wherePivot('branch_id', $this->branchId)
            ->where('roles.slug', 'shop-manager')
            ->exists()
    )->toBeFalse();
});

it('A7b: removeRole with org scope does not remove branch-scoped row of same role', function () {
    $this->user->assignRole('shop-manager', $this->orgId);                   // org-wide
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId); // branch-scoped

    $shopManagerRole = Role::where('slug', 'shop-manager')->first();
    // Remove the org-wide assignment only (no branchId)
    $this->user->removeRole($shopManagerRole, $this->orgId);

    // Branch-scoped row must still exist
    expect(
        $this->user->roles()
            ->wherePivot('organization_id', $this->orgId)
            ->wherePivot('branch_id', $this->branchId)
            ->where('roles.slug', 'shop-manager')
            ->exists()
    )->toBeTrue();

    // Org-wide row must be gone
    expect(
        $this->user->roles()
            ->wherePivot('organization_id', $this->orgId)
            ->wherePivotNull('branch_id')
            ->where('roles.slug', 'shop-manager')
            ->exists()
    )->toBeFalse();
});

// =============================================================================
// A8 — getHighestRoleLevelInContext
// =============================================================================

it('A8: getHighestRoleLevelInContext returns max level in context', function () {
    $this->user->assignRole('staff', $this->orgId);         // level 30
    $this->user->assignRole('org-manager', $this->orgId);  // level 80

    expect($this->user->getHighestRoleLevelInContext($this->orgId))->toBe(80);
});

it('A8b: getHighestRoleLevelInContext returns 0 when user has no roles in context', function () {
    expect($this->user->getHighestRoleLevelInContext($this->orgId))->toBe(0);
});

it('A8c: branch context includes org-wide roles when computing highest level', function () {
    $this->user->assignRole('org-manager', $this->orgId);                    // level 80 org-wide
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId); // level 60 branch

    // Branch context includes BOTH → max is 80
    expect($this->user->getHighestRoleLevelInContext($this->orgId, $this->branchId))->toBe(80);
});

it('A8d: branch-scoped role contributes level only in branch context', function () {
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId); // level 60

    // Org-only context → shop-manager not in scope → level = 0
    expect($this->user->getHighestRoleLevelInContext($this->orgId))->toBe(0);

    // Branch context → shop-manager IS in scope → level = 60
    expect($this->user->getHighestRoleLevelInContext($this->orgId, $this->branchId))->toBe(60);
});

// =============================================================================
// A9 — getAllPermissions: deduplicates across multiple roles
// =============================================================================

it('A9: getAllPermissions deduplicates shared permissions across roles', function () {
    // Both org-manager and staff have catalog.view — should appear only once
    $this->user->assignRole('staff', $this->orgId);        // has catalog.view
    $this->user->assignRole('org-manager', $this->orgId); // also has catalog.view

    $perms = $this->user->getAllPermissions($this->orgId);

    $catalogViewCount = $perms->where('slug', 'catalog.view')->count();
    expect($catalogViewCount)->toBe(1, 'catalog.view should appear exactly once after dedup');
});

it('A9b: getAllPermissions returns union of all in-context role permissions', function () {
    // staff has catalog.view+create+update+import+export + material subset + menu.view (11 total)
    // org-manager has a superset of staff's permissions
    $this->user->assignRole('staff', $this->orgId);
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId);

    // In branch context, user has both staff (org-wide) and shop-manager (branch) permissions
    $perms = $this->user->getAllPermissions($this->orgId, $this->branchId);

    // Must have shop-manager-only perms like iam.assign
    expect($perms->pluck('slug'))->toContain('iam.assign');
    // Must have staff-only perms like catalog.create
    expect($perms->pluck('slug'))->toContain('catalog.create');
});

it('A9c: getAllPermissions returns empty collection when user has no roles in context', function () {
    $perms = $this->user->getAllPermissions($this->orgId);
    expect($perms)->toHaveCount(0);
});

// =============================================================================
// A10 — syncRolesInScope: replaces all roles within a scope
// =============================================================================

it('A10: syncRolesInScope replaces existing roles in the scope', function () {
    $this->user->assignRole('staff', $this->orgId);
    $this->user->assignRole('shop-manager', $this->orgId); // will be replaced

    $this->user->syncRolesInScope(['org-manager'], $this->orgId);

    $roles = $this->user->getRolesForContext($this->orgId);
    $slugs = $roles->pluck('slug')->all();

    expect($slugs)->toContain('org-manager')
        ->and($slugs)->not->toContain('staff')
        ->and($slugs)->not->toContain('shop-manager');
});

it('A10b: syncRolesInScope does not affect other orgs', function () {
    $this->user->assignRole('staff', $this->orgId);
    $this->user->assignRole('org-admin', $this->otherOrg->id);

    $this->user->syncRolesInScope(['org-manager'], $this->orgId);

    // Other org's assignment must be untouched
    $otherRoles = $this->user->getRolesForContext($this->otherOrg->id);
    expect($otherRoles->pluck('slug'))->toContain('org-admin');
});

it('A10c: syncRolesInScope with empty array removes all roles from scope', function () {
    $this->user->assignRole('staff', $this->orgId);
    $this->user->assignRole('shop-manager', $this->orgId);

    $this->user->syncRolesInScope([], $this->orgId);

    $roles = $this->user->getRolesForContext($this->orgId);
    expect($roles)->toHaveCount(0);
});

it('A10d: syncRolesInScope does not affect branch-scoped roles in same org', function () {
    $this->user->assignRole('staff', $this->orgId);                           // org-wide
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId);  // branch-scoped

    // Sync only org-wide scope (no branchId arg)
    $this->user->syncRolesInScope(['org-manager'], $this->orgId);

    // Branch-scoped shop-manager must still exist
    expect(
        $this->user->roles()
            ->wherePivot('organization_id', $this->orgId)
            ->wherePivot('branch_id', $this->branchId)
            ->where('roles.slug', 'shop-manager')
            ->exists()
    )->toBeTrue();
});

// =============================================================================
// A11 — assignRole: exception on invalid slug
// =============================================================================

it('A11: assignRole with non-existent slug throws InvalidArgumentException', function () {
    expect(fn () => $this->user->assignRole('this-role-does-not-exist', $this->orgId))
        ->toThrow(InvalidArgumentException::class);
});

// =============================================================================
// A12 — cross-org isolation
// =============================================================================

it('A12: hasPermission returns false for a permission the user has in a different org', function () {
    $this->user->assignRole('org-admin', $this->otherOrg->id);

    // Check in THIS org — should be false
    expect($this->user->hasPermission('catalog.approve', $this->orgId))->toBeFalse();
});

it('A12b: getRolesForContext strictly isolates by org', function () {
    $this->user->assignRole('org-admin', $this->otherOrg->id);

    $roles = $this->user->getRolesForContext($this->orgId);

    expect($roles)->toHaveCount(0);
});

// =============================================================================
// A13 — removeRole: no-op when assignment does not exist
// =============================================================================

it('A13: removeRole on a non-existent assignment does not error', function () {
    $staffRole = Role::where('slug', 'staff')->first();

    // user has no roles — removeRole should silently succeed (detach 0 rows)
    expect(fn () => $this->user->removeRole($staffRole, $this->orgId))
        ->not->toThrow(Throwable::class);
});

// =============================================================================
// A14 — organizations() relation: deduplication
// =============================================================================

it('A14: organizations() returns distinct orgs even when user has multiple roles in same org', function () {
    // Assign 3 different roles in the same org
    $this->user->assignRole('staff', $this->orgId);
    $this->user->assignRole('shop-manager', $this->orgId);
    $this->user->assignRole('org-manager', $this->orgId);

    $orgs = $this->user->organizations()->get();

    // Should return exactly 1 org, not 3 (distinct() deduplicates the join)
    expect($orgs)->toHaveCount(1)
        ->and($orgs->first()->id)->toBe($this->orgId);
});

it('A14b: organizations() returns all orgs the user has roles in', function () {
    $this->user->assignRole('staff', $this->orgId);
    $this->user->assignRole('org-admin', $this->otherOrg->id);

    $orgs = $this->user->organizations()->get();

    $orgIds = $orgs->pluck('id')->sort()->values()->all();
    expect($orgIds)->toContain($this->orgId)
        ->and($orgIds)->toContain($this->otherOrg->id)
        ->and($orgs)->toHaveCount(2);
});

// =============================================================================
// A15 — getRolesForContext: null org with non-null branch (undefined contract)
// =============================================================================

it('A15: getRolesForContext(null, branchId) silently ignores branch and returns only global roles', function () {
    // The method short-circuits on `$organizationId === null` (line 106)
    // and returns global-only rows regardless of the branch argument.
    $this->user->assignRole('staff');                                          // global
    $this->user->assignRole('shop-manager', $this->orgId, $this->branchId);  // branch-scoped

    // Passing null org with a branch id → behaves like getRolesForContext(null, null)
    $roles = $this->user->getRolesForContext(null, $this->branchId);

    $slugs = $roles->pluck('slug')->all();
    expect($slugs)->toBe(['staff'])
        ->and($roles)->toHaveCount(1);
});
