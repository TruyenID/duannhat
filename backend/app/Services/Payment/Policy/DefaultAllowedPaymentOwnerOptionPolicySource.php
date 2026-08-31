<?php

namespace App\Services\Payment\Policy;

use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;
use App\Services\Payment\Policy\Persistence\EloquentPaymentOwnerOptionPolicySource;

/**
 * NO LONGER BOUND — kept only as an explicit "allow everything" double for tests.
 *
 * This was the bound implementation of {@see PaymentOwnerOptionPolicySource}
 * long after the HQ payment-option policy API it was waiting for had shipped.
 * The result: HQ could set an option to `blocked` and this answered `Allowed`
 * for it anyway, so brand policy was written and then read by nobody (#F3). The
 * real adapter is
 * {@see EloquentPaymentOwnerOptionPolicySource}.
 *
 * Do not re-bind this in the container.
 */
final class DefaultAllowedPaymentOwnerOptionPolicySource implements PaymentOwnerOptionPolicySource
{
    public function resolve(string $brandId, string $optionId): UpstreamPolicyState
    {
        return UpstreamPolicyState::Allowed;
    }
}
