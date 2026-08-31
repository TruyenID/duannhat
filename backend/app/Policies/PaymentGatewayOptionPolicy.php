<?php

namespace App\Policies;

use App\Models\PaymentGatewayOption;
use App\Models\User;
use App\Omnify\Modules\PaymentGatewayOption\Policies\PaymentGatewayOptionPolicyBase;
use App\Policies\Traits\ChecksPaymentAdminContext;

class PaymentGatewayOptionPolicy extends PaymentGatewayOptionPolicyBase
{
    use ChecksPaymentAdminContext;

    public function view(User $user, PaymentGatewayOption $record): bool
    {
        return parent::view($user, $record) && $this->paymentActorCanRead($user);
    }

    public function viewAny(User $user): bool
    {
        return parent::viewAny($user) && $this->paymentActorCanRead($user);
    }
}
