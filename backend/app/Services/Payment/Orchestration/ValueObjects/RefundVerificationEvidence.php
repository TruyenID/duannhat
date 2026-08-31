<?php

namespace App\Services\Payment\Orchestration\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class RefundVerificationEvidence implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $verifiedById;

    public string $authorizationEventId;

    public string $verifiedAt;

    public function __construct(string $verifiedById, string $authorizationEventId, string $verifiedAt)
    {
        $this->verifiedById = MutationCommand::uuid($verifiedById, 'verifiedById');
        $this->authorizationEventId = MutationCommand::safeToken($authorizationEventId, 'authorizationEventId', 255);
        $this->verifiedAt = MutationCommand::isoDateTime($verifiedAt, 'verifiedAt');
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
