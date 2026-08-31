<?php

namespace App\Services\Payment\Gateway\Results;

use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Payment\Gateway\ValueObjects\GatewayNextAction;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use InvalidArgumentException;
use JsonSerializable;

final readonly class GatewayPaymentResult implements JsonSerializable
{
    public string $rawStatus;

    public function __construct(
        public PaymentAttemptStateEnum $state,
        string $rawStatus,
        public ?ProviderObjectReference $payment = null,
        public ?Money $processedMoney = null,
        public ?GatewayNextAction $nextAction = null,
        public RedactedData $summary = new RedactedData,
    ) {
        $rawStatus = trim($rawStatus);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $rawStatus) !== 1) {
            throw new InvalidArgumentException('rawStatus must be a safe provider status code.');
        }

        if ($state === PaymentAttemptStateEnum::ActionRequired && $nextAction === null) {
            throw new InvalidArgumentException('An action-required result must include a next action.');
        }

        if ($state !== PaymentAttemptStateEnum::ActionRequired && $nextAction !== null) {
            throw new InvalidArgumentException('Only an action-required result may include a next action.');
        }

        $this->rawStatus = $rawStatus;

        if ($state === PaymentAttemptStateEnum::Prepared) {
            throw new InvalidArgumentException('A gateway adapter cannot return the local prepared state.');
        }

        if (in_array($state, [PaymentAttemptStateEnum::ProviderPending, PaymentAttemptStateEnum::ActionRequired, PaymentAttemptStateEnum::Processing, PaymentAttemptStateEnum::Succeeded], true) && $payment === null) {
            throw new InvalidArgumentException("A {$state->value} result requires a provider payment identity.");
        }

        if ($state === PaymentAttemptStateEnum::Succeeded && $processedMoney === null) {
            throw new InvalidArgumentException('A succeeded result requires processed money evidence.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'state' => $this->state->value,
            'raw_status' => $this->rawStatus,
            'payment' => $this->payment,
            'processed_money' => $this->processedMoney,
            'next_action_type' => $this->nextAction?->type->value,
            'summary' => $this->summary,
        ];
    }
}
