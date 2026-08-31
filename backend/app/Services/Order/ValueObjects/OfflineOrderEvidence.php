<?php

namespace App\Services\Order\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

/** Opaque, signed evidence. Only OrderEvidenceVerificationPort may trust its claims. */
final readonly class OfflineOrderEvidence implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $deviceId;

    public string $issuerId;

    public string $issuedAt;

    public string $expiresAt;

    public function __construct(
        string $deviceId,
        string $issuerId,
        public int $catalogRevision,
        string $issuedAt,
        string $expiresAt,
        public string $keyId,
        public string $signature,
    ) {
        $this->deviceId = MutationCommand::uuid($deviceId, 'deviceId');
        $this->issuerId = MutationCommand::uuid($issuerId, 'issuerId');
        $this->issuedAt = MutationCommand::isoDateTime($issuedAt, 'issuedAt');
        $this->expiresAt = MutationCommand::isoDateTime($expiresAt, 'expiresAt');
        if ($catalogRevision < 1 || trim($keyId) === '' || trim($signature) === '' || $expiresAt <= $issuedAt) {
            throw new \InvalidArgumentException('Offline order evidence envelope is invalid.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
