<?php

namespace App\Services\Payment\Policy;

use App\Services\Payment\Policy\Contracts\PaymentOwnerOptionPolicySource;
use App\Services\Payment\Policy\Enums\UpstreamPolicyState;

/** Test/dev override keyed by "{brandId}:{optionId}". */
final class InMemoryPaymentOwnerOptionPolicySource implements PaymentOwnerOptionPolicySource
{
    /** @param array<string, UpstreamPolicyState> $states */
    public function __construct(private array $states = []) {}

    public function resolve(string $brandId, string $optionId): UpstreamPolicyState
    {
        return $this->states["{$brandId}:{$optionId}"] ?? UpstreamPolicyState::Allowed;
    }

    public function set(string $brandId, string $optionId, UpstreamPolicyState $state): void
    {
        $this->states["{$brandId}:{$optionId}"] = $state;
    }
}
