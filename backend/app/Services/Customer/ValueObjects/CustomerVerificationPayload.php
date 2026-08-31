<?php

namespace App\Services\Customer\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class CustomerVerificationPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $verificationEventId;

    public function __construct(public string $emailVerifiedAt, string $verificationEventId, public CustomerVerificationSource $source)
    {
        $this->verificationEventId = MutationCommand::safeToken($verificationEventId, 'verificationEventId', 255);
    }

    public function jsonSerialize(): array
    {
        return ['email_verified_at' => $this->emailVerifiedAt, 'verification_event_id' => $this->verificationEventId, 'source' => $this->source->value];
    }
}
