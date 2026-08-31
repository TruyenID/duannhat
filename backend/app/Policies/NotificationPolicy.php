<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\Notification;
use App\Models\NotificationRecipient;
use App\Models\User;

/**
 * Authorization for `/api/v1/me/notifications/*` endpoints.
 *
 * Row-level access is enforced at the SERVICE layer via
 * `NotificationRecipient::forRecipient($user)` — the policy only gates the
 * coarse-grained "can view my own inbox" and "can interact with this
 * specific notification row" decisions.
 *
 * Phase A: own-inbox only. Admin audit authorization (`viewAdmin`) is added
 * in Phase 5 alongside the HQ controllers.
 */
class NotificationPolicy
{
    /**
     * Every authenticated user can view their own inbox. No role gate.
     */
    public function viewInbox(User $user): bool
    {
        return true;
    }

    /**
     * Caller may interact with a notification (mark seen/read/dismissed) only
     * if they are one of its recipients. "Not a recipient" is collapsed to
     * 404 at the controller layer to avoid existence leaks.
     */
    public function update(User $user, Notification $notification): bool
    {
        return NotificationRecipient::query()
            ->forRecipient($user)
            ->where('notification_id', $notification->id)
            ->exists();
    }

    /**
     * HQ audit — "who sent what to whom" across a brand's organizations.
     *
     * Phase A gate: caller must have a console organization binding AND the
     * brand's console_organization_id must match. Tighter role gate
     * (brand_admin vs editor) is deferred to the project's role matrix — see
     * README.md open question "Who can CRUD audiences / templates / routing".
     * Until then, any user with brand access can VIEW the audit list.
     */
    public function viewAdmin(User $user, Brand $brand): bool
    {
        return $user->console_organization_id !== null
            && $user->console_organization_id === $brand->console_organization_id;
    }

    /**
     * plan-012 T1.4 — manage notification_audiences under the brand.
     *
     * Same brand-access gate as viewAdmin for Phase A; a tighter role gate
     * (brand_admin only) comes with the role-matrix follow-up (see
     * plan-012 README.md open question).
     *
     * Additionally: `is_system=true` rows are protected at the controller
     * level against PATCH/DELETE regardless of this permission.
     */
    public function manageAudiences(User $user, Brand $brand): bool
    {
        return $this->viewAdmin($user, $brand);
    }

    /**
     * plan-012 T2.4 — CRUD `notification_templates` rows under the brand.
     * Same brand-access gate as viewAdmin for Phase B; controller enforces
     * is_system protection directly on PATCH / DELETE.
     */
    public function manageTemplates(User $user, Brand $brand): bool
    {
        return $this->viewAdmin($user, $brand);
    }

    /**
     * plan-012 T3.9 — CRUD `notification_channel_routes` under the brand.
     * Same brand-access gate as viewAdmin for Phase C; tighter role gating
     * defers to the role-matrix follow-up tracked in plan-012 README.md.
     */
    public function manageRouting(User $user, Brand $brand): bool
    {
        return $this->viewAdmin($user, $brand);
    }

    /**
     * plan-023 M3 T3.5 — CRUD `notification_schedules` rows + pause/resume/
     * cancel under the brand. Same brand-access gate as viewAdmin; the
     * freeze-window cancel guard (T3.9) is enforced inside the
     * NotificationScheduleCanceller service rather than here.
     */
    public function manageSchedules(User $user, Brand $brand): bool
    {
        return $this->viewAdmin($user, $brand);
    }

    /**
     * plan-023 M4 T4.8 — list / manually add / un-suppress
     * `notification_email_suppressions` rows under the brand. Same
     * brand-access gate as viewAdmin; tighter role gating defers to
     * the role-matrix work in the plan-012 README.
     */
    public function manageSuppressions(User $user, Brand $brand): bool
    {
        return $this->viewAdmin($user, $brand);
    }

    /**
     * plan-012 T4.11 — dispatch a custom notification from the HQ composer.
     * Same brand-access gate for Phase D; audiences/templates the operator
     * can pick from are independently gated by manageAudiences / manageTemplates.
     */
    public function compose(User $user, Brand $brand): bool
    {
        return $this->viewAdmin($user, $brand);
    }
}
