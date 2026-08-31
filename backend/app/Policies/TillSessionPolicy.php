<?php

namespace App\Policies;

use App\Models\TillSession;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Plan 030 + Plan 032 — Cashier Shift authorization.
 *
 * Branch-scoped: every TillSession belongs to one branch_id. A cashier may
 * only interact with sessions of the branch they resolved via X-Shop-Slug
 * (ResolvePosShop) — enforced by the {@see belongsToUserOrg} org check
 * combined with the shop_id-vs-session.branch_id check in the controller.
 *
 * No `reopen` action exists — settled is irreversible by design (BR-TS07).
 *
 * Plan-032 actions (manager+ only — `org-admin || org-manager || shop-manager`):
 *
 * | Action          | shop-staff | staff | shop-manager | org-manager | org-admin |
 * |-----------------|------------|-------|--------------|-------------|-----------|
 * | viewCurrent     |     ✅      |   ✅   |       ✅      |      ✅      |     ✅     |
 * | view            |     ✅      |   ✅   |       ✅      |      ✅      |     ✅     |
 * | open            |     ✅      |   ✅   |       ✅      |      ✅      |     ✅     |
 * | recordEvent     |     ✅      |   ✅   |       ✅      |      ✅      |     ✅     |
 * | close           |     ✅      |   ✅   |       ✅      |      ✅      |     ✅     |
 * | abandon         |     ✅      |   ✅   |       ✅      |      ✅      |     ✅     |
 * | viewStale       |     ❌      |   ❌   |       ✅      |      ✅      |     ✅     |
 * | forceAbandon    |     ❌      |   ❌   |       ✅      |      ✅      |     ✅     |
 * | manualSettle    |     ❌      |   ❌   |       ✅      |      ✅      |     ✅     |
 *
 * Role mapping (from MaterialLotPolicy precedent):
 *   org-admin (level 100)   → HQ admin
 *   org-manager (level 80)  → brand-wide manager
 *   shop-manager (level 60) → per-branch manager
 *   staff (level 30)        → operational staff
 *   shop-staff (level 10)   → cashier
 *
 * Role checks use $user->hasRoleInContext('role-key', $orgId, $branchId).
 * NEVER use raw `$user->level >= N` — that pattern does not exist anywhere
 * in this codebase.
 */
class TillSessionPolicy
{
    use ResolvesOrganization;

    /**
     * GET /pos/till/current — any user in the org can ask. Branch check is
     * done by the controller against $request->attributes->get('shop_id').
     */
    public function viewCurrent(User $user): bool
    {
        return true;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, TillSession $session): bool
    {
        return $this->belongsToUserOrg($user, $session);
    }

    public function open(User $user): bool
    {
        return true;
    }

    public function recordEvent(User $user, TillSession $session): bool
    {
        return $this->belongsToUserOrg($user, $session);
    }

    public function close(User $user, TillSession $session): bool
    {
        return $this->belongsToUserOrg($user, $session);
    }

    /**
     * Plan-046 — a handover is a cashier-driven settle on the caller's own shop,
     * same authorization surface as close(). Chain-summary access is branch-scoped
     * in the controller (chainSummary filters by the resolved shop_id → 404
     * cross-branch), so it needs no separate ability here.
     */
    public function handover(User $user, TillSession $session): bool
    {
        return $this->belongsToUserOrg($user, $session);
    }

    public function abandon(User $user, TillSession $session): bool
    {
        return $this->belongsToUserOrg($user, $session);
    }

    // =========================================================================
    //  Plan-032 — Manager exit doors (force-abandon / manual-settle / view stale)
    // =========================================================================

    /**
     * GET /pos/till/sessions/stale — list stale + expired sessions. Org boundary
     * is enforced inside the service query (filters by request-resolved org_id),
     * so the policy only needs to gate role.
     */
    public function viewStale(User $user): bool
    {
        return $this->isHqRole($user) || $this->isShopManager($user);
    }

    /**
     * POST /pos/till/sessions/{session}/force-abandon — manager dứt khoát huỷ
     * ca treo, kèm reason_code + optional reason_detail. Bypasses the
     * SHIFT_HAS_PAYMENTS guard that cashier-driven abandon() enforces.
     */
    public function forceAbandon(User $user, TillSession $session): bool
    {
        return $this->belongsToUserOrg($user, $session)
            && ($this->isHqRole($user) || $this->isShopManager($user));
    }

    /**
     * POST /pos/till/sessions/{session}/manual-settle — manager reconcile an
     * expired session post-hoc. Status check (must be `expired`) lives in the
     * service, NOT the policy.
     */
    public function manualSettle(User $user, TillSession $session): bool
    {
        return $this->belongsToUserOrg($user, $session)
            && ($this->isHqRole($user) || $this->isShopManager($user));
    }

    // =========================================================================
    //  Role helpers — copied verbatim from MaterialLotPolicy to keep the
    //  codebase pattern uniform. Do not refactor into a shared trait without
    //  reviewing all 7+ policies that use this exact pattern.
    // =========================================================================

    private function isHqRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId);
    }

    private function isShopManager(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();
        $branchId = $this->resolveBranchId();

        return $user->hasRoleInContext('shop-manager', $organizationId, $branchId);
    }

    /**
     * Pull the request-resolved branch id (set by ResolveShopFromSlug
     * middleware on shop-scoped routes). When present, role checks must
     * accept role rows pinned to THAT branch in addition to org-wide
     * rows — otherwise a shop-manager assigned to one specific branch
     * hits a silent 403 because hasRoleInContext($slug, $org, null)
     * only matches `branch_id IS NULL` pivot rows.
     */
    private function resolveBranchId(): ?string
    {
        return request()?->attributes->get('shop_id');
    }
}
