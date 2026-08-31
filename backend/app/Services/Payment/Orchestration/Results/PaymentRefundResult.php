<?php

namespace App\Services\Payment\Orchestration\Results;

use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\DomainMutation\MutationCommand;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use InvalidArgumentException;

final readonly class PaymentRefundResult
{
    public string $refundId;

    public function __construct(
        string $refundId,
        public int $version,
        public PaymentRefundStateEnum $state,
        public ?ProviderObjectReference $providerRefund,
        public ?Money $processedMoney,
    ) {
        if ($version < 1 || ($state === PaymentRefundStateEnum::Succeeded && ($providerRefund === null || $processedMoney === null))) {
            throw new InvalidArgumentException('Refund outcome is invalid.');
        }

        $this->refundId = MutationCommand::uuid($refundId, 'refundId');
    }
}
