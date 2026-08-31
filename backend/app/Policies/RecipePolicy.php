<?php

namespace App\Policies;

use App\Models\Recipe;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * RecipePolicy — HQ recipe management + approval workflow.
 *
 * plan-040 (NEW-MR / authz matrix): the flat "belongs-to-org" gate let any
 * org member (incl. shop-tier read-only roles) edit and even approve recipes.
 * The seeded RBAC matrix (IamSeeder) already draws the line the DESIGN.md
 * authorization matrix documents:
 *
 *   - Edit a recipe (non-approval fields) → `material.update`
 *     → org-admin, org-manager, staff  (shop-manager / shop-staff read-only)
 *   - Approve / reject a recipe          → `material.approve`
 *     → org-admin, org-manager only  (staff/"editor" cannot approve)
 *
 * Authorization uses the permission pivot so Platform `tempo-*` roles and
 * tenant-customized roles share the same behavior. The "cannot approve own
 * submission" invariant stays in RecipeService::approve (separate concern).
 */
class RecipePolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'material.view');
    }

    public function view(User $user, Recipe $recipe): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $recipe, 'material.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'material.create');
    }

    public function update(User $user, Recipe $recipe): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $recipe, 'material.update');
    }

    public function delete(User $user, Recipe $recipe): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $recipe, 'material.delete');
    }

    public function restore(User $user, Recipe $recipe): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $recipe, 'material.update');
    }

    // ---------------------------------------------------------------
    // Approval workflow (HasApprovalWorkflow trait — plan-003)
    // ---------------------------------------------------------------
    // The "cannot approve own submission" check stays in
    // RecipeService::approve (mirrors ProductService idiom).

    public function submitForApproval(User $user, Recipe $recipe): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $recipe, 'material.update');
    }

    public function approve(User $user, Recipe $recipe): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $recipe, 'material.approve');
    }

    public function reject(User $user, Recipe $recipe): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $recipe, 'material.approve');
    }
}
