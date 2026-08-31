<?php

namespace App\Services\Payment\Orchestration\Results;

use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Payment\Gateway\ValueObjects\GatewayNextAction;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use InvalidArgumentException;

final readonly class PaymentAttemptOutcome
{
    public function __construct(
        public PaymentAttemptStateEnum $state,
        public ?ProviderObjectReference $providerPayment,
        public ?Money $processedMoney,
        public ?GatewayNextAction $nextAction,
    ) {
        if (($state === PaymentAttemptStateEnum::ActionRequired) !== ($nextAction !== null)) {
            throw new InvalidArgumentException('Only action-required outcomes carry a next action.');
        }

        if ($state === PaymentAttemptStateEnum::Succeeded && ($providerPayment === null || $processedMoney === null)) {
            throw new InvalidArgumentException('Succeeded payment requires provider and money evidence.');
        }
    }
}
