<?php

namespace App\Policies;

use App\Models\PaymentGatewayProvider;
use App\Models\User;
use App\Omnify\Modules\PaymentGatewayProvider\Policies\PaymentGatewayProviderPolicyBase;
use App\Policies\Traits\ChecksPaymentAdminContext;

class PaymentGatewayProviderPolicy extends PaymentGatewayProviderPolicyBase
{
    use ChecksPaymentAdminContext;

    public function view(User $user, PaymentGatewayProvider $record): bool
    {
        return parent::view($user, $record) && $this->paymentActorCanRead($user);
    }

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && $this->paymentActorCanRead($user);
    }
}
