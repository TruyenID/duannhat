<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use App\Services\Payment\Gateway\Enums\GatewayNextActionType;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * Type-safe client handoff. Customer-scoped action material is intentionally JSON-visible to the
 * authenticated client, but kept outside instance properties so debug/export normalizers cannot log it.
 */
final readonly class GatewayNextAction
{
    private function __construct(
        public GatewayNextActionType $type,
        private EphemeralSecret $clientPayload,
    ) {}

    public static function redirect(#[SensitiveParameter] string $url): self
    {
        $parts = parse_url($url);
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new InvalidArgumentException('Redirect next action requires an HTTPS URL without user information.');
        }

        return self::fromPayload(GatewayNextActionType::Redirect, ['url' => $url]);
    }

    public static function qrCode(#[SensitiveParameter] string $payload): self
    {
        if ($payload === '' || strlen($payload) > 4096) {
            throw new InvalidArgumentException('QR next-action payload is invalid.');
        }

        return self::fromPayload(GatewayNextActionType::QrCode, ['payload' => $payload]);
    }

    /** @param array<string, bool|int|string> $handoff */
    public static function providerSdk(#[SensitiveParameter] array $handoff): self
    {
        if ($handoff === []) {
            throw new InvalidArgumentException('Provider SDK handoff cannot be empty.');
        }

        foreach ($handoff as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1 || (! is_scalar($value) || is_float($value))) {
                throw new InvalidArgumentException('Provider SDK handoff must be a flat scalar map with canonical keys.');
            }
        }

        return self::fromPayload(GatewayNextActionType::ProviderSdk, $handoff);
    }

    public static function displayInstructions(string $messageCode): self
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{0,99}$/', $messageCode) !== 1) {
            throw new InvalidArgumentException('Display instructions require a stable message code.');
        }

        return self::fromPayload(GatewayNextActionType::DisplayInstructions, ['message_code' => $messageCode]);
    }

    public static function wait(int $retryAfterSeconds): self
    {
        if ($retryAfterSeconds < 1 || $retryAfterSeconds > 86400) {
            throw new InvalidArgumentException('Wait retry interval must be between 1 and 86400 seconds.');
        }

        return self::fromPayload(GatewayNextActionType::Wait, ['retry_after_seconds' => $retryAfterSeconds]);
    }

    /** @return array<string, bool|int|string> */
    public function payload(): array
    {
        /** @var array<string, bool|int|string> $payload */
        $payload = json_decode($this->clientPayload->reveal(), true, flags: JSON_THROW_ON_ERROR);

        return $payload;
    }

    /** @return array{type: string, payload: array<string, bool|int|string>} */
    public function toClientArray(): array
    {
        return ['type' => $this->type->value, 'payload' => $this->payload()];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Client next-action material cannot be serialized for queues or storage.');
    }

    /** @return array{type: GatewayNextActionType, payload_sha256: string} */
    public function __debugInfo(): array
    {
        return [
            'type' => $this->type,
            'payload_sha256' => hash('sha256', $this->clientPayload->reveal()),
        ];
    }

    /** @param array<string, bool|int|string> $payload */
    private static function fromPayload(GatewayNextActionType $type, array $payload): self
    {
        return new self($type, new EphemeralSecret(json_encode($payload, JSON_THROW_ON_ERROR)));
    }
}
