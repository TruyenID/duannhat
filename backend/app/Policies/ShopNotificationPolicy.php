<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

/**
 * Plan-023 M6 T6.4 — shop-level admin gate for notification surfaces.
 *
 * Registered as five named Gate abilities in AppServiceProvider:
 *
 *   shop.notifications.manageAudiences
 *   shop.notifications.manageTemplates
 *   shop.notifications.manageRouting
 *   shop.notifications.compose
 *   shop.notifications.viewAudit
 *
 * Distinct ability names dodge Laravel's "one policy per model"
 * dispatch — `Gate::authorize('manageAudiences', $brand)` would
 * otherwise always hit the HQ NotificationPolicy. Shop controllers
 * call `$this->authorize('shop.notifications.manageAudiences', $shop)`
 * directly.
 *
 * Decision 21 (plan-023 DESIGN): all five inherit the same
 * `viewShopAdmin` gate. Per-feature permission split (e.g. a role
 * that can edit templates but not compose broadcasts) defers until a
 * customer asks — re-open as a separate plan.
 */
class ShopNotificationPolicy
{
    private function viewShopAdmin(User $user, Branch $shop): bool
    {
        return $user->console_organization_id !== null
            && $user->console_organization_id === $shop->console_organization_id;
    }

    public function manageAudiences(User $user, Branch $shop): bool
    {
        return $this->viewShopAdmin($user, $shop);
    }

    public function manageTemplates(User $user, Branch $shop): bool
    {
        return $this->viewShopAdmin($user, $shop);
    }

    public function manageRouting(User $user, Branch $shop): bool
    {
        return $this->viewShopAdmin($user, $shop);
    }

    public function compose(User $user, Branch $shop): bool
    {
        return $this->viewShopAdmin($user, $shop);
    }

    public function viewAudit(User $user, Branch $shop): bool
    {
        return $this->viewShopAdmin($user, $shop);
    }
}
