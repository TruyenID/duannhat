<?php

namespace App\Policies;

use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\User;
use App\Omnify\Modules\PaymentGatewayConnectionOption\Policies\PaymentGatewayConnectionOptionPolicyBase;
use App\Policies\Traits\ChecksPaymentAdminContext;
use App\Services\Payment\Admin\PaymentAdminAuthorizationService;

class PaymentGatewayConnectionOptionPolicy extends PaymentGatewayConnectionOptionPolicyBase
{
    use ChecksPaymentAdminContext;

    public function __construct(
        private PaymentAdminAuthorizationService $authorization,
    ) {}

    public function view(User $user, PaymentGatewayConnectionOption $record): bool
    {
        if (! parent::view($user, $record)) {
            return false;
        }

        $organizationId = $this->paymentOrganizationId();

        $connection = $record->relationLoaded('connection')
            ? $record->connection
            : $record->connection()->first();

        return $organizationId !== null
            && $connection instanceof PaymentGatewayConnection
            && $this->authorization->canViewConnection(
                $user,
                $connection,
                $organizationId,
                $this->paymentBranchId(),
            );
    }

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && $this->paymentActorCanRead($user);
    }
}
