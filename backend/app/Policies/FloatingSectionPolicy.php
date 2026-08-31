<?php

namespace App\Policies;

use App\Models\FloatingSection;
use App\Models\User;
use App\Policies\Traits\ChecksShopContext;
use App\Policies\Traits\ResolvesOrganization;

/**
 * FloatingSectionPolicy — org-scoped management surface.
 *
 * HQ reads require menu.view and mutations require menu.manage in the
 * request organization. Cross-org access is additionally blocked via the
 * organization_id carried on each section.
 *
 * shop* abilities mirror MenuPolicy — a shop only ever sees its own branch
 * clone (branch_id set), never the HQ master.
 */
class FloatingSectionPolicy
{
    use ChecksShopContext;
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $organizationId !== null
            && $user->hasPermission('menu.view', $organizationId);
    }

    public function view(User $user, FloatingSection $floatingSection): bool
    {
        return $this->hasPermissionInSectionOrg($user, $floatingSection, 'menu.view');
    }

    public function create(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $organizationId !== null
            && $user->hasPermission('menu.manage', $organizationId);
    }

    public function update(User $user, FloatingSection $floatingSection): bool
    {
        return $this->hasPermissionInSectionOrg($user, $floatingSection, 'menu.manage');
    }

    public function delete(User $user, FloatingSection $floatingSection): bool
    {
        return $this->hasPermissionInSectionOrg($user, $floatingSection, 'menu.manage');
    }

    /**
     * Shop-side: view a branch's cloned floating section. Never true for the
     * HQ master row (branch_id null) — a shop only manages its own clone.
     */
    public function shopView(User $user, FloatingSection $floatingSection): bool
    {
        return $this->belongsToOrganization($user, $floatingSection)
            && $this->belongsToResolvedShop($floatingSection)
            && $floatingSection->branch_id !== null
            && $this->isShopUser($user, $floatingSection->branch_id);
    }

    /**
     * Shop-side: toggle product availability / edit the clone's own schedule.
     * Open to any shop staff — daily-operations toggle, same as Menu.
     */
    public function shopUpdateAvailability(User $user, FloatingSection $floatingSection): bool
    {
        return $this->shopView($user, $floatingSection);
    }

    /**
     * Shop-side: override a product's promotional price. Restricted to Shop
     * Manager and above, same as Menu's shopUpdatePrice.
     */
    public function shopUpdatePrice(User $user, FloatingSection $floatingSection): bool
    {
        return $this->belongsToOrganization($user, $floatingSection)
            && $this->belongsToResolvedShop($floatingSection)
            && $floatingSection->branch_id !== null
            && $this->isShopManager($user, $floatingSection->branch_id);
    }

    private function hasPermissionInSectionOrg(User $user, FloatingSection $section, string $permission): bool
    {
        return $this->belongsToUserOrg($user, $section)
            && $user->hasPermission($permission, $section->organization_id);
    }
}
