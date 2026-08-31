<?php

namespace App\Services\Payment\Policy\Contracts;

use App\Services\Payment\Policy\Enums\UpstreamPolicyState;

/** HQ/brand upstream allow/deny for a catalog option — not shop-settable. */
interface PaymentOwnerOptionPolicySource
{
    public function resolve(string $brandId, string $optionId): UpstreamPolicyState;
}
