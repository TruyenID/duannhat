<?php

namespace App\Services\Payment\Orchestration\Internal;

use App\Models\PaymentAttempt;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Services\Payment\Orchestration\Contracts\PaymentSnapshot;

final readonly class PaymentAttemptSnapshot implements PaymentSnapshot
{
    private function __construct(
        private string $id,
        private string $organizationId,
        private string $orderId,
        private PaymentAttemptStateEnum $state,
        private int $version,
    ) {}

    public static function fromModel(PaymentAttempt $attempt): self
    {
        $state = $attempt->state instanceof PaymentAttemptStateEnum
            ? $attempt->state
            : PaymentAttemptStateEnum::from($attempt->state);

        return new self(
            (string) $attempt->id,
            (string) $attempt->organization_id,
            (string) $attempt->customer_order_id,
            $state,
            (int) $attempt->version,
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
        return $this->orderId;
    }

    public function status(): string
    {
        return $this->state->value;
    }
}
