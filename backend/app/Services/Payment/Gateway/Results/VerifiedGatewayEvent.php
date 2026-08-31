<?php

namespace App\Services\Payment\Gateway\Results;

use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonSerializable;

final readonly class VerifiedGatewayEvent implements JsonSerializable
{
    public string $providerEventId;

    public string $eventType;

    public string $payloadSha256;

    public function __construct(
        string $providerEventId,
        string $eventType,
        public DateTimeImmutable $occurredAt,
        string $payloadSha256,
        public ?ProviderObjectReference $payment = null,
        public ?ProviderObjectReference $refund = null,
        public RedactedData $payload = new RedactedData,
    ) {
        $this->providerEventId = self::text($providerEventId, 'providerEventId');
        $this->eventType = self::text($eventType, 'eventType');
        $payloadSha256 = strtolower(trim($payloadSha256));

        if (preg_match('/^[0-9a-f]{64}$/', $payloadSha256) !== 1) {
            throw new InvalidArgumentException('payloadSha256 must be a SHA-256 hex digest.');
        }

        $this->payloadSha256 = $payloadSha256;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'provider_event_id' => $this->providerEventId,
            'event_type' => $this->eventType,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'payload_sha256' => $this->payloadSha256,
            'payment' => $this->payment,
            'refund' => $this->refund,
            'payload' => $this->payload,
        ];
    }

    private static function text(string $value, string $name): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 255) {
            throw new InvalidArgumentException("{$name} must contain between 1 and 255 characters.");
        }

        return $value;
    }
}
