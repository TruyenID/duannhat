<?php

namespace App\Policies;

use App\Models\PaymentPolicyRevision;
use App\Models\User;
use App\Omnify\Modules\PaymentPolicyRevision\Policies\PaymentPolicyRevisionPolicyBase;
use App\Policies\Traits\ChecksPaymentAdminContext;
use App\Services\Payment\Admin\PaymentAdminAuthorizationService;

class PaymentPolicyRevisionPolicy extends PaymentPolicyRevisionPolicyBase
{
    use ChecksPaymentAdminContext;

    public function __construct(
        private PaymentAdminAuthorizationService $authorization,
    ) {}

    public function view(User $user, PaymentPolicyRevision $record): bool
    {
        if (! parent::view($user, $record)) {
            return false;
        }

        $organizationId = $this->paymentOrganizationId();

        return $organizationId !== null
            && $this->authorization->belongsToOrganization($record, $organizationId)
            && $this->authorization->isCashier($user, $organizationId, $this->paymentBranchId());
    }

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && $this->paymentActorCanRead($user);
    }
}
