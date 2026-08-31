<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\PaymentRefund;
use App\Omnify\Enums\PaymentRefundStateEnum;
use App\Services\Payment\Orchestration\Contracts\PaymentSnapshot;

final readonly class PaymentRefundSnapshot implements PaymentSnapshot
{
    private function __construct(
        private string $id,
        private string $organizationId,
        private string $attemptId,
        private PaymentRefundStateEnum $state,
        private int $version,
    ) {}

    public static function fromModel(PaymentRefund $refund): self
    {
        $state = $refund->state instanceof PaymentRefundStateEnum
            ? $refund->state
            : PaymentRefundStateEnum::from($refund->state);

        return new self(
            (string) $refund->id,
            (string) $refund->organization_id,
            (string) $refund->payment_attempt_id,
            $state,
            (int) $refund->version,
        );
    }

    public function aggregateId(): string
    {
        return $this->id;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function orderId(): string
    {
        return $this->attemptId;
    }

    public function status(): string
    {
        return $this->state->value;
    }
}
