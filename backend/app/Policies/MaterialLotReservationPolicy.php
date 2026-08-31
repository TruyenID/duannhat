<?php

namespace App\Policies;

use App\Models\MaterialBatch;
use App\Models\MaterialLot;
use App\Models\MaterialLotReservation;
use App\Models\User;
use App\Models\Warehouse;
use App\Policies\Traits\ResolvesOrganization;

/**
 * Two rings live in this one policy, and they are deliberately NOT the same
 * ability names.
 *
 * `create` / `release` / `renew` gate the HQ route
 * (`/hq/{brandSlug}/material-lot-reservations`). That surface is org-wide —
 * one call can touch a lot in any branch — so it stays HQ-role only.
 *
 * `*AtShop` gate the shop route (`/shops/{shopSlug}/material-lot-reservations`,
 * #3112). Same service underneath, narrower reach: the lot must sit in a
 * warehouse that belongs to THE SHOP ON THE URL. That is the tight direction
 * chosen on purpose — the production-batch screen already picks a
 * `warehouse_id`, and anything looser lets shop A hold shop B's material with
 * the shortage only surfacing in shop B's kitchen, mid-service.
 *
 * A warehouse with `branch_id = NULL` (a shared/central warehouse) therefore
 * fails the shop check. That is the scope of #3112, not an oversight — a
 * central-warehouse model needs its own ruling before it can be reached from a
 * shop-scoped route.
 *
 * Do NOT widen `create`/`release`/`renew` to reach the shop case. The two
 * abilities are separate so that widening one can never silently widen the
 * other.
 */
class MaterialLotReservationPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return $this->isOrgUser($user);
    }

    public function view(User $user, MaterialLotReservation $reservation): bool
    {
        return $this->belongsToUserOrg($user, $reservation) && $this->isOrgUser($user);
    }

    public function create(User $user): bool
    {
        return $this->isHqRole($user);
    }

    public function release(User $user, MaterialLotReservation $reservation): bool
    {
        return $this->belongsToUserOrg($user, $reservation) && $this->isHqRole($user);
    }

    public function renew(User $user, MaterialLotReservation $reservation): bool
    {
        return $this->belongsToUserOrg($user, $reservation) && $this->isHqRole($user);
    }

    // =====================================================================
    //  Shop scope (#3112)
    // =====================================================================

    /**
     * List the holds attached to one production batch of THIS shop.
     *
     * Scoped through the batch rather than "every reservation of the org" so
     * the shop route can never page through another branch's holds.
     */
    public function viewAnyAtShop(User $user, MaterialBatch $batch): bool
    {
        return $this->belongsToUserOrgWithPermission($user, $batch, 'material.view')
            && $this->warehouseIsAtShop($batch->warehouse_id);
    }

    /**
     * Hold qty on a lot from the shop's own warehouse.
     *
     * The permission is `material.create` — the exact predicate
     * `MaterialBatchPolicy::create` uses. That equality is the point of #3112:
     * whoever can create the batch on that screen must be able to hold its
     * lots, otherwise the checkbox is a 403 aimed at precisely the people who
     * need it (which is what #3077 refused to ship).
     */
    public function createAtShop(User $user, MaterialLot $lot, ?MaterialBatch $batch = null): bool
    {
        if (! $this->belongsToUserOrgWithPermission($user, $lot, 'material.create')) {
            return false;
        }

        if (! $this->lotIsAtShop($lot)) {
            return false;
        }

        // A hold may name the batch it serves. That batch has to be this
        // shop's too — otherwise a shop could pin its own lot to a sibling
        // shop's batch and make the release path answer to someone else.
        if ($batch !== null) {
            return $this->belongsToUserOrg($user, $batch)
                && $this->warehouseIsAtShop($batch->warehouse_id);
        }

        return true;
    }

    public function releaseAtShop(User $user, MaterialLotReservation $reservation): bool
    {
        return $this->mutateAtShop($user, $reservation);
    }

    public function renewAtShop(User $user, MaterialLotReservation $reservation): bool
    {
        return $this->mutateAtShop($user, $reservation);
    }

    private function mutateAtShop(User $user, MaterialLotReservation $reservation): bool
    {
        if (! $this->belongsToUserOrgWithPermission($user, $reservation, 'material.update')) {
            return false;
        }

        $lot = $reservation->relationLoaded('materialLot')
            ? $reservation->materialLot
            : MaterialLot::find($reservation->material_lot_id);

        return $lot !== null && $this->lotIsAtShop($lot);
    }

    private function lotIsAtShop(MaterialLot $lot): bool
    {
        return $this->warehouseIsAtShop($lot->warehouse_id);
    }

    /**
     * Fail-closed on purpose in both directions: no shop context on the
     * request (i.e. this ability was reached from a non-shop route) is a
     * refusal, and so is a warehouse with no branch.
     */
    private function warehouseIsAtShop(?string $warehouseId): bool
    {
        $shopId = request()?->attributes->get('shop_id');

        if ($shopId === null || $warehouseId === null) {
            return false;
        }

        $branchId = Warehouse::whereKey($warehouseId)->value('branch_id');

        return $branchId !== null && (string) $branchId === (string) $shopId;
    }

    private function isHqRole(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $user->hasRoleInContext('org-admin', $organizationId)
            || $user->hasRoleInContext('org-manager', $organizationId);
    }

    private function isOrgUser(User $user): bool
    {
        $organizationId = $this->resolveLocalOrgId();

        return $this->isHqRole($user)
            || $user->hasRoleInContext('shop-manager', $organizationId)
            || $user->hasRoleInContext('staff', $organizationId)
            || $user->hasRoleInContext('shop-staff', $organizationId);
    }
}
