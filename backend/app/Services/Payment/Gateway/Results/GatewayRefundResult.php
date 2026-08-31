<?php

namespace App\Services\Payment\Gateway\Results;

use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use InvalidArgumentException;
use JsonSerializable;

final readonly class GatewayRefundResult implements JsonSerializable
{
    public string $rawStatus;

    public function __construct(
        public PaymentRefundStateEnum $state,
        string $rawStatus,
        public ?ProviderObjectReference $refund = null,
        public ?Money $processedMoney = null,
        public RedactedData $summary = new RedactedData,
    ) {
        $rawStatus = trim($rawStatus);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,99}$/', $rawStatus) !== 1) {
            throw new InvalidArgumentException('rawStatus must be a safe provider status code.');
        }

        $this->rawStatus = $rawStatus;

        if (in_array($state, [PaymentRefundStateEnum::Prepared, PaymentRefundStateEnum::Submitted], true)) {
            throw new InvalidArgumentException("A gateway adapter cannot return the local {$state->value} state.");
        }

        if (in_array($state, [PaymentRefundStateEnum::Pending, PaymentRefundStateEnum::Succeeded], true) && $refund === null) {
            throw new InvalidArgumentException("A {$state->value} refund result requires a provider refund identity.");
        }

        if ($state === PaymentRefundStateEnum::Succeeded && $processedMoney === null) {
            throw new InvalidArgumentException('A succeeded refund result requires processed money evidence.');
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'state' => $this->state->value,
            'raw_status' => $this->rawStatus,
            'refund' => $this->refund,
            'processed_money' => $this->processedMoney,
            'summary' => $this->summary,
        ];
    }
}
