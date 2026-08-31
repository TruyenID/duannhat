<?php

namespace App\Policies;

use App\Models\ShopPaymentOption;
use App\Models\User;
use App\Omnify\Modules\ShopPaymentOption\Policies\ShopPaymentOptionPolicyBase;
use App\Policies\Traits\ChecksPaymentAdminContext;
use App\Services\Payment\Admin\PaymentAdminAuthorizationService;

class ShopPaymentOptionPolicy extends ShopPaymentOptionPolicyBase
{
    use ChecksPaymentAdminContext;

    public function __construct(
        private PaymentAdminAuthorizationService $authorization,
    ) {}

    public function view(User $user, ShopPaymentOption $record): bool
    {
        if (! parent::view($user, $record)) {
            return false;
        }

        $organizationId = $this->paymentOrganizationId();

        return $organizationId !== null
            && $this->authorization->belongsToOrganization($record, $organizationId)
            && $this->authorization->isCashier($user, $organizationId, $record->branch_id);
    }

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && $this->paymentActorCanRead($user);
    }

    public function update(User $user, ShopPaymentOption $record): bool
    {
        if (! parent::update($user, $record)) {
            return false;
        }

        $organizationId = $this->paymentOrganizationId();

        return $organizationId !== null
            && $this->authorization->canManageShopPaymentOption($user, $record, $organizationId);
    }
}
