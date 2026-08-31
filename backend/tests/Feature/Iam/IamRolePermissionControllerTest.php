<?php

use App\Models\Brand;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Iam\RoleCustomizationService;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(IamSeeder::class);

    $this->organization = Organization::factory()->create();
    $this->orgId = $this->organization->id;

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organization->console_organization_id,
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('org-admin', $this->orgId);

    // org-manager has iam.member.view but NOT iam.permissions (cannot edit permission matrix)
    $this->manager = User::factory()->create();
    $this->manager->assignRole('org-manager', $this->orgId);

    $this->brandSlug = $this->brand->slug;
});

/**
 * Create an org-scoped (non-global) role for the given console organization.
 * This is the only kind of role the tenant-facing endpoint may mutate (#844).
 */
function makeOrgRole(string $consoleOrgId, string $slug = 'custom-role', int $level = 40): Role
{
    return Role::create([
        'console_organization_id' => $consoleOrgId,
        'name' => ucfirst(str_replace('-', ' ', $slug)),
        'slug' => $slug,
        'level' => $level,
        'description' => 'Org-scoped custom role.',
    ]);
}

// =========================================================================
//  C1 — roles index: returns system roles + caller-owned org roles only
// =========================================================================

it('C1: roles index returns all 5 system roles ordered by level descending', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/roles")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['slug', 'name', 'level', 'description', 'permissions'],
            ],
        ]);

    $roles = $response->json('data');
    expect($roles)->toHaveCount(5);

    // Verify descending order by level
    $levels = collect($roles)->pluck('level')->toArray();
    expect($levels)->toBe([100, 80, 60, 30, 10]);

    // org-admin should have 33 permissions
    $orgAdmin = collect($roles)->firstWhere('slug', 'org-admin');
    expect($orgAdmin['permissions'])->toHaveCount(33);
});

it('C1b: roles index includes caller org-scoped roles but not other tenants (#844)', function () {
    makeOrgRole($this->organization->console_organization_id, 'own-custom');

    $otherOrg = Organization::factory()->create();
    makeOrgRole($otherOrg->console_organization_id, 'other-custom');

    $slugs = collect(
        $this->actingAs($this->admin)
            ->getJson("/api/v1/hq/{$this->brandSlug}/iam/roles")
            ->assertOk()
            ->json('data')
    )->pluck('slug');

    expect($slugs)->toContain('own-custom')
        ->not->toContain('other-custom');
});

// =========================================================================
//  C2 — roles index: 403 without iam.member.view
// =========================================================================

it('C2: roles index returns 403 for user without iam.member.view', function () {
    $shopStaff = User::factory()->create();
    $shopStaff->assignRole('shop-staff', $this->orgId);

    $this->actingAs($shopStaff)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/roles")
        ->assertForbidden();
});

// =========================================================================
//  C3 — roles update: editing a global template forks it (copy-on-write, #847)
//       (was: global/system roles immutable → 403, reversed by plan-fix-issue-847)
// =========================================================================

it('C3: editing a global system role forks it into an org-scoped copy, leaving the template pristine (204)', function () {
    $global = Role::where('slug', 'staff')->whereNull('console_organization_id')->first();
    $globalBefore = $global->permissions()->pluck('slug')->sort()->values()->toArray();

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$global->id}", [
            'permission_slugs' => ['catalog.view', 'menu.view'],
        ])
        ->assertNoContent();

    // The shared global template must be untouched.
    expect($global->fresh()->permissions()->pluck('slug')->sort()->values()->toArray())->toBe($globalBefore);

    // An org-scoped copy now carries the edited set.
    $clone = Role::where('slug', 'staff')
        ->where('console_organization_id', $this->organization->console_organization_id)
        ->first();
    expect($clone)->not->toBeNull()
        ->and($clone->permissions()->pluck('slug')->sort()->values()->toArray())->toBe(['catalog.view', 'menu.view']);
});

it('C3b: roles update returns 403 for non-org-admin caller', function () {
    $role = Role::where('slug', 'staff')->first();

    $this->actingAs($this->manager)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
            'permission_slugs' => ['catalog.view'],
        ])
        ->assertForbidden();
});

it('C3c: roles update returns 422 for unknown permission slugs (org-scoped role)', function () {
    $role = makeOrgRole($this->organization->console_organization_id);

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
            'permission_slugs' => ['does.not.exist'],
        ])
        ->assertUnprocessable();
});

it('C3d: roles update syncs permissions for a caller-owned org-scoped role (204)', function () {
    $role = makeOrgRole($this->organization->console_organization_id);
    $newPermissions = Permission::whereIn('slug', ['catalog.view', 'menu.view'])->pluck('slug')->toArray();

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
            'permission_slugs' => $newPermissions,
        ])
        ->assertNoContent();

    $actual = $role->fresh()->permissions()->pluck('slug')->sort()->values()->toArray();
    expect($actual)->toBe(['catalog.view', 'menu.view']);
});

it('C3e: roles update returns 404 for a role owned by another organization (#844)', function () {
    $otherOrg = Organization::factory()->create();
    $role = makeOrgRole($otherOrg->console_organization_id, 'foreign-role');

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
            'permission_slugs' => ['catalog.view'],
        ])
        ->assertNotFound();

    // The cross-tenant role must remain untouched.
    expect($role->fresh()->permissions()->count())->toBe(0);
});

// =========================================================================
//  C4 — roles update: forking the global org-admin repoints the caller pivot (#847)
//       (was: 403; reversed by plan-fix-issue-847)
// =========================================================================

it('C4: forking the global org-admin creates an org copy and repoints the caller pivot (204)', function () {
    $global = Role::where('slug', 'org-admin')->whereNull('console_organization_id')->first();

    // Keep iam.permissions so the self-lockout guard does not fire.
    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$global->id}", [
            'permission_slugs' => ['catalog.view', 'iam.permissions'],
        ])
        ->assertNoContent();

    $clone = Role::where('slug', 'org-admin')
        ->where('console_organization_id', $this->organization->console_organization_id)
        ->first();
    expect($clone)->not->toBeNull()
        ->and($clone->permissions()->pluck('slug')->sort()->values()->toArray())->toBe(['catalog.view', 'iam.permissions']);

    // Global template unchanged (still the full 33).
    expect($global->fresh()->permissions()->count())->toBe(33);

    // The admin's assignment was repointed from the global template to the org clone.
    $adminRoleIds = $this->admin->fresh()->roles()
        ->wherePivot('organization_id', $this->orgId)
        ->pluck('roles.id')->toArray();
    expect($adminRoleIds)->toContain($clone->id)
        ->not->toContain($global->id);
});

// =========================================================================
//  CW / SL — copy-on-write internals + self-lockout guard (plan-fix-issue-847)
// =========================================================================

it('CW2: editing the same global template twice reuses the org copy (no duplicate row)', function () {
    $global = Role::where('slug', 'staff')->whereNull('console_organization_id')->first();

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$global->id}", ['permission_slugs' => ['catalog.view']])
        ->assertNoContent();
    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$global->id}", ['permission_slugs' => ['menu.view']])
        ->assertNoContent();

    $copies = Role::where('slug', 'staff')
        ->where('console_organization_id', $this->organization->console_organization_id)->get();
    expect($copies)->toHaveCount(1)
        ->and($copies->first()->permissions()->pluck('slug')->toArray())->toBe(['menu.view']);
});

it('CW3: a user assigned the global template is repointed to the org clone and sees the edit', function () {
    $u = User::factory()->create();
    $u->assignRole('staff', $this->orgId); // pivot → global staff, org-scoped
    expect($u->hasPermission('catalog.approve', $this->orgId))->toBeFalse();

    $global = Role::where('slug', 'staff')->whereNull('console_organization_id')->first();
    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$global->id}", ['permission_slugs' => ['catalog.approve']])
        ->assertNoContent();

    $clone = Role::where('slug', 'staff')
        ->where('console_organization_id', $this->organization->console_organization_id)->first();
    $ids = $u->fresh()->roles()->wherePivot('organization_id', $this->orgId)->pluck('roles.id')->toArray();
    expect($ids)->toContain($clone->id)->not->toContain($global->id);
    expect($u->fresh()->hasPermission('catalog.approve', $this->orgId))->toBeTrue();
});

it('CW4: forking a template in one org does not affect another tenant', function () {
    $otherOrg = Organization::factory()->create();
    $otherUser = User::factory()->create();
    $otherUser->assignRole('staff', $otherOrg->id); // pivot → global staff, scoped to otherOrg

    $global = Role::where('slug', 'staff')->whereNull('console_organization_id')->first();
    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$global->id}", ['permission_slugs' => ['catalog.view']])
        ->assertNoContent();

    // The other tenant's user still points at the pristine global template.
    $ids = $otherUser->fresh()->roles()->wherePivot('organization_id', $otherOrg->id)->pluck('roles.id')->toArray();
    expect($ids)->toContain($global->id);
});

it('SL1: removing iam.permissions from the only assigned admin role is blocked (422 IAM_LAST_ADMIN_LOCKOUT)', function () {
    // The admin holds the global org-admin (the org's only iam.permissions grant); the
    // manager holds org-manager (no iam.permissions).
    $global = Role::where('slug', 'org-admin')->whereNull('console_organization_id')->first();

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$global->id}", [
            'permission_slugs' => ['catalog.view'], // drops iam.permissions
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'IAM_LAST_ADMIN_LOCKOUT');

    // Guard ran BEFORE the fork → no orphaned org copy left behind.
    expect(Role::where('slug', 'org-admin')
        ->where('console_organization_id', $this->organization->console_organization_id)->exists())->toBeFalse();
});

it('SL2: removing iam.permissions is allowed when another assigned role retains it', function () {
    // Give the manager an org-scoped role that also grants iam.permissions.
    $second = makeOrgRole($this->organization->console_organization_id, 'co-admin', 90);
    $second->permissions()->sync(Permission::where('slug', 'iam.permissions')->pluck('id'));
    $this->manager->assignRole($second, $this->orgId);

    $global = Role::where('slug', 'org-admin')->whereNull('console_organization_id')->first();
    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$global->id}", [
            'permission_slugs' => ['catalog.view'], // drops iam.permissions from org-admin
        ])
        ->assertNoContent();
});

it('SL4: an unassigned global template does NOT rescue the guard (guard-not-inert)', function () {
    // The global org-admin template always grants iam.permissions, but nobody in a fresh
    // second org is assigned it. Stripping iam.permissions from that org's only assigned
    // admin role must still 422 — the template must not count.
    $org2 = Organization::factory()->create();
    $brand2 = Brand::factory()->create(['console_organization_id' => $org2->console_organization_id]);
    $admin2 = User::factory()->create();
    $onlyAdmin = makeOrgRole($org2->console_organization_id, 'org2-admin', 100);
    $onlyAdmin->permissions()->sync(Permission::whereIn('slug', ['iam.member.view', 'iam.permissions'])->pluck('id'));
    $admin2->assignRole($onlyAdmin, $org2->id);

    $this->actingAs($admin2)
        ->putJson("/api/v1/hq/{$brand2->slug}/iam/roles/{$onlyAdmin->id}", [
            'permission_slugs' => ['iam.member.view'], // drops iam.permissions
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'IAM_LAST_ADMIN_LOCKOUT');
});

it('AP1: after forking org-manager, assigning that role to a new member attaches the org copy', function () {
    // Fork org-manager (level 80) for this org — the level-100 admin may assign it (80 < 100).
    $global = Role::where('slug', 'org-manager')->whereNull('console_organization_id')->first();
    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$global->id}", [
            'permission_slugs' => ['catalog.view', 'menu.view'],
        ])->assertNoContent();

    $clone = Role::where('slug', 'org-manager')
        ->where('console_organization_id', $this->organization->console_organization_id)->first();

    // Assign "org-manager" to a brand-new member; they must inherit the org copy, not the template.
    $newMember = User::factory()->create();
    $this->actingAs($this->admin)
        ->postJson("/api/v1/hq/{$this->brandSlug}/iam/members/{$newMember->id}/assign", [
            'role_slug' => 'org-manager',
        ])
        ->assertSuccessful();

    $ids = $newMember->fresh()->roles()->wherePivot('organization_id', $this->orgId)->pluck('roles.id')->toArray();
    expect($ids)->toContain($clone->id)->not->toContain($global->id);
});

// =========================================================================
//  C5 — permissions index: returns all permissions grouped by domain
// =========================================================================

it('C5: permissions index returns all 33 permissions in 6 groups', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/permissions")
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['group', 'permissions' => [
                    '*' => ['slug', 'name'],
                ]],
            ],
        ]);

    $data = $response->json('data');
    $groups = collect($data)->pluck('group')->toArray();
    sort($groups);
    expect($groups)->toBe(['catalog', 'iam', 'inventory', 'material', 'menu', 'shop']);

    $total = collect($data)->sum(fn ($g) => count($g['permissions']));
    expect($total)->toBe(33);
});

it('C5b: permissions index returns 403 for user without iam.member.view', function () {
    $shopStaff = User::factory()->create();
    $shopStaff->assignRole('shop-staff', $this->orgId);

    $this->actingAs($shopStaff)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/permissions")
        ->assertForbidden();
});

// =========================================================================
//  C6 — roles update edge cases (exercised against a caller-owned org role)
// =========================================================================

it('C6: roles update with empty array clears all permissions', function () {
    $role = makeOrgRole($this->organization->console_organization_id);
    $role->permissions()->sync(Permission::where('slug', 'catalog.view')->pluck('id'));

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
            'permission_slugs' => [],
        ])
        ->assertNoContent();

    expect($role->fresh()->permissions()->count())->toBe(0);
});

it('C6b: roles update with duplicate slugs is idempotent (no duplicates in DB)', function () {
    $role = makeOrgRole($this->organization->console_organization_id);

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
            'permission_slugs' => ['catalog.view', 'catalog.view'],
        ])
        ->assertNoContent();

    // sync() deduplicates — should have exactly 1 row
    expect($role->fresh()->permissions()->count())->toBe(1);
});

it('C6c: roles update with all 33 permissions works and syncs correctly', function () {
    $role = makeOrgRole($this->organization->console_organization_id);
    $allSlugs = Permission::pluck('slug')->toArray();

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
            'permission_slugs' => $allSlugs,
        ])
        ->assertNoContent();

    expect($role->fresh()->permissions()->count())->toBe(33);
});

it('C6d: roles update case-sensitive slug validation rejects uppercase', function () {
    $role = makeOrgRole($this->organization->console_organization_id);

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
            'permission_slugs' => ['CATALOG.VIEW'],
        ])
        ->assertUnprocessable();
});

it('C6e: roles update with non-existent role ID returns 404', function () {
    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/00000000-0000-0000-0000-000000000000", [
            'permission_slugs' => ['catalog.view'],
        ])
        ->assertNotFound();
});

it('C6f: roles update permission_slugs is required (missing key → 422)', function () {
    $role = makeOrgRole($this->organization->console_organization_id);

    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [])
        ->assertUnprocessable();
});

it('C6g: roles index returns permissions in sorted order per role', function () {
    $response = $this->actingAs($this->admin)
        ->getJson("/api/v1/hq/{$this->brandSlug}/iam/roles")
        ->assertOk();

    foreach ($response->json('data') as $role) {
        $perms = $role['permissions'];
        $sorted = $perms;
        sort($sorted);
        expect($perms)->toBe($sorted, "Permissions for [{$role['slug']}] should be sorted alphabetically");
    }
});

// =========================================================================
//  C7 — cross-role permission isolation after sync
// =========================================================================

it('C7: syncing permissions for one org role does not affect another role', function () {
    $roleA = makeOrgRole($this->organization->console_organization_id, 'custom-a', 40);
    $roleB = makeOrgRole($this->organization->console_organization_id, 'custom-b', 20);
    $roleB->permissions()->sync(Permission::where('slug', 'menu.view')->pluck('id'));

    $roleBPermsBefore = $roleB->permissions()->pluck('slug')->sort()->values()->toArray();

    // Sync roleA to a completely different set
    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$roleA->id}", [
            'permission_slugs' => ['catalog.view'],
        ])
        ->assertNoContent();

    // roleB permissions unchanged
    $roleBPermsAfter = $roleB->fresh()->permissions()->pluck('slug')->sort()->values()->toArray();
    expect($roleBPermsAfter)->toBe($roleBPermsBefore);
});

// =========================================================================
//  C8 — permission update takes effect immediately (no stale cache)
// =========================================================================

it('C8: hasPermission reflects updated permissions immediately after sync', function () {
    $role = makeOrgRole($this->organization->console_organization_id);
    $user = User::factory()->create();
    $user->assignRole($role, $this->orgId);

    // Before sync: user does NOT have catalog.approve
    expect($user->hasPermission('catalog.approve', $this->orgId))->toBeFalse();

    // Sync catalog.approve onto the org role
    $this->actingAs($this->admin)
        ->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
            'permission_slugs' => ['catalog.approve'],
        ])
        ->assertNoContent();

    // After sync: hasPermission must see the new value (fresh DB query, no cache)
    expect($user->hasPermission('catalog.approve', $this->orgId))->toBeTrue();
});

// =========================================================================
//  Unauthenticated access → 401
// =========================================================================

it('unauthenticated: roles index returns 401', function () {
    $this->getJson("/api/v1/hq/{$this->brandSlug}/iam/roles")
        ->assertUnauthorized();
});

it('unauthenticated: roles update returns 401', function () {
    $role = Role::where('slug', 'staff')->first();
    $this->putJson("/api/v1/hq/{$this->brandSlug}/iam/roles/{$role->id}", [
        'permission_slugs' => ['catalog.view'],
    ])->assertUnauthorized();
});

it('unauthenticated: permissions index returns 401', function () {
    $this->getJson("/api/v1/hq/{$this->brandSlug}/iam/permissions")
        ->assertUnauthorized();
});

// =============================================================================
// #1666 — fork và sync là MỘT hành động
// =============================================================================

/**
 * `IamRoleController::update` chạy `cloneForOrg()` — vốn tự nguyên tử — RỒI mới
 * mở transaction thứ hai cho lần sync. Hỏng giữa hai cái để lại tổ chức ở một
 * trạng thái nó chưa từng yêu cầu: đã tách khỏi template dùng chung, mọi phân
 * công đã trỏ sang bản fork, và bản fork vẫn mang quyền của TEMPLATE chứ không
 * phải bản sửa.
 *
 * Không ai được thêm quyền, nên đây không phải lỗ hổng leo thang — nhưng tổ
 * chức từ đó vĩnh viễn rời template, còn quản trị viên thì thấy lỗi và tưởng
 * không có gì xảy ra. Thử lại sẽ tự lành (`firstOrCreate` idempotent), và đó
 * đúng là lý do nó nằm im không ai thấy.
 *
 * Lái ở seam sync: lỗi phải rơi ĐÚNG giữa fork và sync.
 */
it('#1666: cuộn lại cả việc chuyển phân công khi lần sync quyền hỏng', function () {
    $global = Role::where('slug', 'org-admin')->whereNull('console_organization_id')->first();
    $consoleOrgId = (string) $this->organization->console_organization_id;

    // Bản sao của tổ chức đã tồn tại sẵn, nên `cloneForOrg` chỉ còn việc CHUYỂN
    // phân công sang nó — đó là thứ phải bị cuộn lại.
    $clone = makeOrgRole($consoleOrgId, 'org-admin', (int) $global->level);

    expect($this->admin->fresh()->roles()->pluck('roles.id')->contains($global->id))
        ->toBeTrue('mốc xuất phát: phân công nằm trên template');

    // Nạp sẵn quan hệ để `loadMissing` không truy vấn — nếu không, lỗi sẽ rơi
    // TRƯỚC lúc fork và phép đo này thành vô nghĩa (đúng cái bẫy vòng đầu).
    $global->load('permissions');

    // `iam.permissions` nằm trong danh sách nên guard THOÁT SỚM, không truy vấn.
    // Bảng trung gian biến mất vì thế chỉ làm vỡ đúng lần sync ở cuối.
    Schema::drop('role_permissions');

    $threw = false;
    try {
        app(RoleCustomizationService::class)->applyPermissions(
            $global,
            $consoleOrgId,
            (string) $this->orgId,
            ['catalog.view', 'iam.permissions'],
        );
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue('lần sync phải vỡ để phép đo này có nghĩa');

    // Với hình dạng CŨ (fork một transaction, sync một transaction khác) việc
    // chuyển phân công đã commit và sẽ SỐNG SÓT qua lần sync hỏng — tổ chức rời
    // template mà quyền vẫn là bộ cũ, còn quản trị viên chỉ thấy một lỗi.
    expect($this->admin->fresh()->roles()->pluck('roles.id')->contains($global->id))
        ->toBeTrue('phân công phải được cuộn lại về template')
        ->and($this->admin->fresh()->roles()->pluck('roles.id')->contains($clone->id))
        ->toBeFalse('không được để phân công nằm lại trên bản sao');
});
