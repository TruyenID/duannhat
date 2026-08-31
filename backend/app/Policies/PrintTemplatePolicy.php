<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PrintTemplate;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;
use App\Support\Iam\RoleTemplateMatrix;

/**
 * plan-053 (#1171) — TR-37, the permission matrix for print templates.
 *
 * The rule the plan states is "brand-level needs an HQ role (org-admin /
 * brand-manager); a shop override needs shop-manager; a cashier sees nothing".
 * Mapped onto the existing IAM matrix ({@see RoleTemplateMatrix})
 * WITHOUT inventing new permission slugs — a new slug would have to be seeded
 * into every existing organization before it granted anything, and a
 * permission that is silently absent fails open on the day it ships.
 *
 *   brand READ  → `menu.manage`     org-admin, org-manager, shop-manager
 *   brand WRITE → `catalog.approve` org-admin, org-manager ONLY
 *   shop  BOTH  → `shop.manage`     org-admin, org-manager, shop-manager
 *
 * `catalog.approve` is the discriminator that already separates HQ authority
 * from shop authority in this codebase, and publishing a brand-wide compliance
 * document is an approval-grade act — the same class of decision as approving
 * a catalog change. `staff` and `shop-staff` (the cashier) hold neither
 * `menu.manage` nor `shop.manage`, so the whole surface is invisible to them.
 */
class PrintTemplatePolicy
{
    use ResolvesOrganization;

    /** HQ list/read of the brand layer. */
    public function viewAny(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'menu.manage');
    }

    public function view(User $user, PrintTemplate $template): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $template, 'menu.manage');
    }

    /** Draft/publish/retire/rollback at the BRAND layer — HQ only. */
    public function manageBrand(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'catalog.approve');
    }

    public function create(User $user): bool
    {
        return $this->manageBrand($user);
    }

    public function update(User $user, PrintTemplate $template): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $template, 'catalog.approve');
    }

    /**
     * Shop override surface. `shop.manage` also covers reading it: TR-37 says
     * a cashier must not even see the menu entry, and `shop.view` (which
     * shop-staff holds) would let them.
     */
    public function manageShopOverride(User $user): bool
    {
        return $this->hasOrganizationPermission($user, 'shop.manage');
    }
}
