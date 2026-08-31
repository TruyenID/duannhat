<?php

namespace App\Policies;

use App\Models\CustomerOrder;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class CustomerOrderPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CustomerOrder $order): bool
    {
        return $this->belongsToUserOrg($user, $order);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * plan-045 — refund a line. Distinct ability (owned here so role-gating can
     * be tightened without touching `update`); device-authed POS bypasses via the
     * AppServiceProvider Gate::before. Currently org-membership scoped like the
     * other order mutations.
     */
    public function refund(User $user, CustomerOrder $order): bool
    {
        return $this->belongsToUserOrg($user, $order);
    }

    public function update(User $user, CustomerOrder $order): bool
    {
        return $this->belongsToUserOrg($user, $order);
    }

    public function delete(User $user, CustomerOrder $order): bool
    {
        return $this->belongsToUserOrg($user, $order);
    }

    public function checkout(User $user, CustomerOrder $order): bool
    {
        return $this->belongsToUserOrg($user, $order);
    }

    public function void(User $user, CustomerOrder $order): bool
    {
        return $this->belongsToUserOrg($user, $order);
    }

    public function cancel(User $user, CustomerOrder $order): bool
    {
        return $this->belongsToUserOrg($user, $order);
    }

    /**
     * Plan-019 — apply a coupon to this order. Same scope as the rest
     * of the policy: user's request-resolved organization must match
     * the order's organization. The service layer enforces brand match
     * + branch eligibility + the per-coupon validation chain.
     */
    public function applyCoupon(User $user, CustomerOrder $order): bool
    {
        return $this->belongsToUserOrg($user, $order);
    }

    /**
     * Plan-019 — release the coupon currently bound to this order.
     */
    public function releaseCoupon(User $user, CustomerOrder $order): bool
    {
        return $this->belongsToUserOrg($user, $order);
    }
}
