<?php

namespace App\Policies;

use App\Models\Menu;
use App\Models\User;
use App\Policies\Traits\ChecksShopContext;
use App\Policies\Traits\ResolvesOrganization;

class MenuPolicy
{
    use ChecksShopContext;
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'menu.view');
    }

    public function view(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.view');
    }

    public function create(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'menu.manage');
    }

    public function update(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.manage');
    }

    public function delete(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.manage');
    }

    public function restore(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.manage');
    }

    public function submit(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.publish');
    }

    public function approve(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.publish');
    }

    public function reject(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.publish');
    }

    public function activate(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.publish');
    }

    public function deactivate(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.publish');
    }

    public function cloneToBranch(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.manage');
    }

    public function checkSync(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.view');
    }

    public function syncFromMaster(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.manage');
    }

    public function manageItems(User $user, Menu $menu): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $menu, 'menu.manage');
    }

    /**
     * Shop-side: view a branch menu.
     *
     * Allowed for any authenticated shop user (manager or staff) whose
     * console org owns the menu, when the menu's branch matches the resolved
     * shop. Covers both clones (from an HQ master) and standalone branch menus
     * created directly on the shop — masters carry branch_id = null and are
     * excluded by `! is_master` (never gate on master_menu_id: a standalone
     * branch menu is a valid, viewable shop menu — see #878).
     */
    public function shopView(User $user, Menu $menu): bool
    {
        return $this->belongsToOrganization($user, $menu)
            && $this->belongsToResolvedShop($menu)
            && ! $menu->is_master
            && $this->isShopUser($user, $menu->branch_id);
    }

    /**
     * Shop-side: toggle availability on a branch menu item.
     *
     * Same scope as shopView. Shop Staff are allowed because availability
     * is the daily-operations toggle for "out of stock" / "86'd" items.
     */
    public function shopUpdateAvailability(User $user, Menu $menu): bool
    {
        return $this->shopView($user, $menu);
    }

    /**
     * Shop-side: override per-shop selling price (or reset it).
     *
     * Restricted to Shop Manager and above. Shop Staff cannot edit prices
     * because pricing is a managerial decision; staff only manage stock.
     */
    public function shopUpdatePrice(User $user, Menu $menu): bool
    {
        return $this->belongsToOrganization($user, $menu)
            && $this->belongsToResolvedShop($menu)
            && ! $menu->is_master
            && $this->isShopManager($user, $menu->branch_id);
    }
}
