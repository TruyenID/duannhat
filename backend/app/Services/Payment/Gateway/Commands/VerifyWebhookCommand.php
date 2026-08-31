<?php

namespace App\Services\Payment\Gateway\Commands;

use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameter;

/** Raw webhook material is readable by the adapter but never serialized or exposed in debug output. */
final readonly class VerifyWebhookCommand implements JsonSerializable
{
    private EphemeralSecret $raw;

    private EphemeralSecret $headerValues;

    /** @var list<string> */
    private array $headerNames;

    public string $correlationId;

    public function __construct(
        public GatewayConnectionData $connection,
        #[SensitiveParameter]
        string $rawBody,
        #[SensitiveParameter]
        array $headers,
        string $correlationId,
        private ?string $clientIp = null,
    ) {
        if ($rawBody === '') {
            throw new InvalidArgumentException('rawBody cannot be empty.');
        }

        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new InvalidArgumentException('Webhook headers must be a string map.');
            }
        }

        $correlationId = trim($correlationId);
        if ($correlationId === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,254}$/', $correlationId) !== 1) {
            throw new InvalidArgumentException('correlationId is invalid.');
        }

        $this->raw = new EphemeralSecret($rawBody);
        $this->headerValues = new EphemeralSecret(json_encode($headers, JSON_THROW_ON_ERROR));
        $this->headerNames = array_values(array_keys($headers));
        $this->correlationId = $correlationId;
    }

    public function rawBody(): string
    {
        return $this->raw->reveal();
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        /** @var array<string, string> $headers */
        $headers = json_decode($this->headerValues->reveal(), true, flags: JSON_THROW_ON_ERROR);

        return $headers;
    }

    public function clientIp(): ?string
    {
        return $this->clientIp;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Commands containing raw webhook material cannot be serialized.');
    }

    /** @return array{connection: GatewayConnectionData, correlation_id: string, payload_sha256: string, header_names: list<string>} */
    public function jsonSerialize(): array
    {
        return [
            'connection' => $this->connection,
            'correlation_id' => $this->correlationId,
            'payload_sha256' => hash('sha256', $this->rawBody()),
            'header_names' => $this->headerNames,
        ];
    }

    /** @return array{connection: GatewayConnectionData, correlationId: string, payloadSha256: string, headerNames: list<string>} */
    public function __debugInfo(): array
    {
        return [
            'connection' => $this->connection,
            'correlationId' => $this->correlationId,
            'payloadSha256' => hash('sha256', $this->rawBody()),
            'headerNames' => $this->headerNames,
        ];
    }
}
