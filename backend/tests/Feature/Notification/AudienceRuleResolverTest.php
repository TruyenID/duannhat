<?php

/**
 * Plan-023 M8 T8.3 — AudienceRuleResolver tests.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Role;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\Notification\AudienceRuleResolver;
use Illuminate\Support\Str;

/**
 * Create a role with a unique slug (Role has no factory).
 */
function makeTestRole(string $slug, string $orgId): Role
{
    return Role::firstOrCreate(
        ['slug' => $slug],
        [
            'id' => (string) Str::uuid(),
            'console_organization_id' => $orgId,
            'name' => ucfirst(str_replace('_', ' ', $slug)),
            'level' => 100,
        ]
    );
}

beforeEach(function () {
    $this->resolver = new AudienceRuleResolver;
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    // Use the test org console_organization_id for roles
    $this->consoleOrgId = '00000000-0000-0000-0000-000000000001';
});

it('M8-5: role_scoped brand resolution returns users with matching role', function () {
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);

    $slug = 'brand_admin_m8_'.Str::random(4);
    $role = makeTestRole($slug, $this->consoleOrgId);
    $user = User::factory()->create();

    $user->assignRole($role, $this->orgId);

    // Product has brand_id — use it as the "model" for the resolver
    $model = new Product(['brand_id' => $brand->id]);

    $result = $this->resolver->resolve([
        'type' => 'role_scoped',
        'role' => $slug,
        'scope' => 'brand',
        'brand_field' => 'brand_id',
    ], $model);

    expect($result->pluck('id')->toArray())->toContain($user->id);
});

it('M8-6: role_scoped branch with custom field returns branch users', function () {
    $slug = 'warehouse_mgr_'.Str::random(4);
    $role = makeTestRole($slug, $this->consoleOrgId);
    $branch = Branch::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);
    $user = User::factory()->create();

    $user->assignRole($role, $this->orgId, $branch->id);

    // Model with a custom branch field (warehouse_id used as branch scope field)
    $transaction = new StockTransaction(['warehouse_id' => $branch->id]);

    $result = $this->resolver->resolve([
        'type' => 'role_scoped',
        'role' => $slug,
        'scope' => 'branch',
        'branch_field' => 'warehouse_id',
    ], $transaction);

    expect($result->pluck('id')->toArray())->toContain($user->id);
});

it('M8-7: model_user null FK returns empty collection', function () {
    $model = new StockTransaction(['created_by_id' => null]);

    $result = $this->resolver->resolve([
        'type' => 'model_user',
        'field' => 'created_by_id',
    ], $model);

    expect($result)->toBeEmpty();
});

it('M8-8: union dedupes when same user appears in multiple members', function () {
    $brand = Brand::factory()->create([
        'console_organization_id' => $this->consoleOrgId,
    ]);

    $slug1 = 'role_a_'.Str::random(4);
    $slug2 = 'role_b_'.Str::random(4);
    $role1 = makeTestRole($slug1, $this->consoleOrgId);
    $role2 = makeTestRole($slug2, $this->consoleOrgId);
    $user = User::factory()->create();

    $user->assignRole($role1, $this->orgId);
    $user->assignRole($role2, $this->orgId);

    $model = new Product(['brand_id' => $brand->id]);

    $result = $this->resolver->resolve([
        'type' => 'union',
        'members' => [
            ['type' => 'role_scoped', 'role' => $slug1, 'scope' => 'brand', 'brand_field' => 'brand_id'],
            ['type' => 'role_scoped', 'role' => $slug2, 'scope' => 'brand', 'brand_field' => 'brand_id'],
        ],
    ], $model);

    // Same user should appear only once
    expect($result->filter(fn ($u) => $u->id === $user->id))->toHaveCount(1);
});
