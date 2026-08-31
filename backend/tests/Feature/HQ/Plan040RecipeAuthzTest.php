<?php

use App\Models\Brand;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Recipe;
use App\Models\Role;
use App\Models\User;
use App\Omnify\Enums\ApprovalStatusEnum;
use App\Policies\RecipePolicy;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Str;

/**
 * plan-040 regression — RecipePolicy enforces the documented authz matrix:
 * approve/reject = HQ approver only (org-admin / org-manager); "editor"
 * (staff) may edit but not approve; shop-tier roles are read-only. The prior
 * flat belongsToUserOrg gate let any org member approve a recipe, which
 * defeats the M7 deduction gate (a forged is_active/approved draft).
 */
function pj40AssignRole(User $user, string $slug, string $orgId): void
{
    $role = Role::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug), 'level' => 50]);
    $user->assignRole($role, $orgId);
}

function pj40RecipeAuthzUser(string $slug): array
{
    test()->seed(IamSeeder::class);

    $orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $orgId, 'console_organization_id' => $orgId]);
    $brand = Brand::factory()->create(['console_organization_id' => $orgId]);
    $output = Material::factory()->create(['organization_id' => $orgId, 'brand_id' => $brand->id]);
    $recipe = Recipe::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brand->id,
        'material_id' => $output->id,
        'approval_status' => ApprovalStatusEnum::Draft->value,
    ]);

    $user = User::factory()->create([]);
    pj40AssignRole($user, $slug, $orgId);

    request()->attributes->set('organization_id', $orgId);

    return [$user, $recipe];
}

it('lets org-admin approve and update a recipe', function () {
    [$user, $recipe] = pj40RecipeAuthzUser('org-admin');
    $policy = new RecipePolicy;

    expect($policy->update($user, $recipe))->toBeTrue();
    expect($policy->approve($user, $recipe))->toBeTrue();
    expect($policy->reject($user, $recipe))->toBeTrue();
});

it('lets org-manager approve and update a recipe', function () {
    [$user, $recipe] = pj40RecipeAuthzUser('org-manager');
    $policy = new RecipePolicy;

    expect($policy->update($user, $recipe))->toBeTrue();
    expect($policy->approve($user, $recipe))->toBeTrue();
});

it('lets staff (editor) update but NOT approve a recipe', function () {
    [$user, $recipe] = pj40RecipeAuthzUser('staff');
    $policy = new RecipePolicy;

    expect($policy->update($user, $recipe))->toBeTrue();
    expect($policy->approve($user, $recipe))->toBeFalse();
    expect($policy->reject($user, $recipe))->toBeFalse();
});

it('denies shop-manager (warehouse role) both update and approve on a recipe', function () {
    [$user, $recipe] = pj40RecipeAuthzUser('shop-manager');
    $policy = new RecipePolicy;

    expect($policy->update($user, $recipe))->toBeFalse();
    expect($policy->approve($user, $recipe))->toBeFalse();
});
