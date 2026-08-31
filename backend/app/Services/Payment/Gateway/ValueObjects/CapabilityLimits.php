<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use InvalidArgumentException;
use JsonSerializable;

final readonly class CapabilityLimits implements JsonSerializable
{
    public function __construct(
        public CapabilityRule $partialCapture,
        public CapabilityRule $partialRefund,
        public CapabilityRule $multipleRefunds,
        public ?int $minimumMinorAmount = null,
        public ?int $maximumMinorAmount = null,
        public ?int $authorizationWindowSeconds = null,
        public ?int $cancelWindowSeconds = null,
        public ?int $refundWindowSeconds = null,
    ) {
        foreach (['minimumMinorAmount', 'maximumMinorAmount', 'authorizationWindowSeconds', 'cancelWindowSeconds', 'refundWindowSeconds'] as $property) {
            if ($this->{$property} !== null && $this->{$property} < 0) {
                throw new InvalidArgumentException("{$property} cannot be negative.");
            }
        }

        if ($minimumMinorAmount !== null && $maximumMinorAmount !== null && $minimumMinorAmount > $maximumMinorAmount) {
            throw new InvalidArgumentException('Minimum amount cannot exceed maximum amount.');
        }
    }

    /** @return array<string, CapabilityRule|int|null> */
    public function jsonSerialize(): array
    {
        return [
            'partial_capture' => $this->partialCapture,
            'partial_refund' => $this->partialRefund,
            'multiple_refunds' => $this->multipleRefunds,
            'minimum_minor_amount' => $this->minimumMinorAmount,
            'maximum_minor_amount' => $this->maximumMinorAmount,
            'authorization_window_seconds' => $this->authorizationWindowSeconds,
            'cancel_window_seconds' => $this->cancelWindowSeconds,
            'refund_window_seconds' => $this->refundWindowSeconds,
        ];
    }
}
