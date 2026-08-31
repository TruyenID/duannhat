<?php

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\IamSeeder;

it('seeds 5 system roles with correct levels', function () {
    $this->seed(IamSeeder::class);

    $expected = [
        'org-admin' => 100,
        'org-manager' => 80,
        'shop-manager' => 60,
        'staff' => 30,
        'shop-staff' => 10,
    ];

    foreach ($expected as $slug => $level) {
        $role = Role::where('slug', $slug)->first();
        expect($role)->not->toBeNull("Role [{$slug}] not found")
            ->and($role->level)->toBe($level);
    }
});

it('seeds 33 permissions grouped by domain', function () {
    $this->seed(IamSeeder::class);

    $expectedGroups = [
        'catalog' => 7,
        'material' => 7,
        'menu' => 3,
        'inventory' => 10,
        'shop' => 3,
        'iam' => 3,
    ];

    foreach ($expectedGroups as $group => $count) {
        expect(Permission::where('group', $group)->count())
            ->toBe($count, "Group [{$group}] should have {$count} permissions");
    }

    expect(Permission::count())->toBe(33);
});

it('seeds idempotently — running twice does not duplicate records', function () {
    $this->seed(IamSeeder::class);
    $this->seed(IamSeeder::class);

    expect(Role::count())->toBe(5);
    expect(Permission::count())->toBe(33);
});

it('org-admin has all 33 permissions', function () {
    $this->seed(IamSeeder::class);

    $admin = Role::where('slug', 'org-admin')->first();
    expect($admin->permissions()->count())->toBe(33);
});

it('shop-staff has only view-level permissions', function () {
    $this->seed(IamSeeder::class);

    $shopStaff = Role::where('slug', 'shop-staff')->first();
    $slugs = $shopStaff->permissions()->pluck('slug')->sort()->values()->toArray();

    expect($slugs)->toEqual([
        'catalog.view',
        'inventory.view',
        'menu.view',
        'shop.view',
    ]);
});

it('org-admin is the only role with shop.create permission', function () {
    $this->seed(IamSeeder::class);

    $rolesWithShopCreate = Permission::where('slug', 'shop.create')
        ->first()
        ->roles()
        ->pluck('slug')
        ->toArray();

    expect($rolesWithShopCreate)->toBe(['org-admin']);
});

// =============================================================================
// E — Per-role permission boundary tests (the full matrix)
// =============================================================================

it('E: org-manager has catalog.approve but NOT iam.assign', function () {
    $this->seed(IamSeeder::class);

    $perms = Role::where('slug', 'org-manager')->first()->permissions()->pluck('slug');

    expect($perms)->toContain('catalog.approve')
        ->and($perms)->not->toContain('iam.assign')
        ->and($perms)->not->toContain('iam.permissions')
        ->and($perms)->toContain('iam.member.view');
});

it('E: shop-manager has iam.assign but NOT catalog.approve and NOT iam.permissions', function () {
    $this->seed(IamSeeder::class);

    $perms = Role::where('slug', 'shop-manager')->first()->permissions()->pluck('slug');

    expect($perms)->toContain('iam.assign')
        ->and($perms)->toContain('iam.member.view')
        ->and($perms)->not->toContain('catalog.approve')
        ->and($perms)->not->toContain('iam.permissions');
});

it('E: staff has no iam permissions at all', function () {
    $this->seed(IamSeeder::class);

    $perms = Role::where('slug', 'staff')->first()->permissions()->pluck('slug');

    expect($perms)->not->toContain('iam.member.view')
        ->and($perms)->not->toContain('iam.assign')
        ->and($perms)->not->toContain('iam.permissions');
});

it('E: shop-staff has no iam or admin permissions', function () {
    $this->seed(IamSeeder::class);

    $perms = Role::where('slug', 'shop-staff')->first()->permissions()->pluck('slug');

    expect($perms)->not->toContain('iam.member.view')
        ->and($perms)->not->toContain('catalog.approve')
        ->and($perms)->not->toContain('catalog.create')
        ->and($perms)->not->toContain('menu.manage')
        ->and($perms)->not->toContain('shop.manage');
});

it('E: iam.permissions belongs only to org-admin', function () {
    $this->seed(IamSeeder::class);

    $roles = Permission::where('slug', 'iam.permissions')
        ->first()
        ->roles()
        ->pluck('slug')
        ->toArray();

    expect($roles)->toBe(['org-admin']);
});

it('E: iam.assign belongs to org-admin and shop-manager only', function () {
    $this->seed(IamSeeder::class);

    $roles = Permission::where('slug', 'iam.assign')
        ->first()
        ->roles()
        ->pluck('slug')
        ->sort()
        ->values()
        ->toArray();

    expect($roles)->toBe(['org-admin', 'shop-manager']);
});

it('E: org-manager has exactly the right number of permissions', function () {
    $this->seed(IamSeeder::class);

    $count = Role::where('slug', 'org-manager')->first()->permissions()->count();

    // 7 catalog + 7 material + 3 menu + 10 inventory + 2 shop (view+manage, no create) + 1 iam (member.view) = 30
    expect($count)->toBe(30);
});

it('E: shop-manager has exactly the right number of permissions', function () {
    $this->seed(IamSeeder::class);

    $count = Role::where('slug', 'shop-manager')->first()->permissions()->count();

    // catalog.view(1) + material.view(1) + 3 menu + 7 inventory + 2 shop + 2 iam = 16
    expect($count)->toBe(16);
});

it('E: staff has exactly the right number of permissions', function () {
    $this->seed(IamSeeder::class);

    $count = Role::where('slug', 'staff')->first()->permissions()->count();

    // catalog: view+create+update+import+export (5) + material: view+create+update+import+export (5) + menu.view (1) = 11
    expect($count)->toBe(11);
});
