<?php

namespace App\Policies\Traits;

use App\Models\User;
use App\Services\Payment\Admin\PaymentAdminAuthorizationService;

/**
 * Resolves HQ/shop payment-admin context from middleware-stamped request attributes.
 */
trait ChecksPaymentAdminContext
{
    protected function paymentOrganizationId(): ?string
    {
        return request()?->attributes->get('organization_id');
    }

    protected function paymentBranchId(): ?string
    {
        return request()?->attributes->get('branch_id')
            ?? request()?->attributes->get('shop_id');
    }

    protected function paymentIdentityBrandId(): ?string
    {
        $brandId = request()?->attributes->get('identity_brand_id');

        return is_string($brandId) && $brandId !== '' ? $brandId : null;
    }

    protected function paymentActorCanRead(User $user): bool
    {
        $organizationId = $this->paymentOrganizationId();

        return $organizationId !== null
            && app(PaymentAdminAuthorizationService::class)
                ->isCashier($user, $organizationId, $this->paymentBranchId());
    }
}
