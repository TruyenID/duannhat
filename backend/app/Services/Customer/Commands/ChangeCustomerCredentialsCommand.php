<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\ValueObjects\CustomerCredentialPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ChangeCustomerCredentialsCommand extends MutationCommand
{
    public string $customerId;

    public string $credentialKind;

    public function __construct(MutationContext $context, string $customerId, string $credentialKind, public CustomerCredentialPayload $payload)
    {
        parent::__construct($context);
        if ($context->organizationId !== null || $context->actorId === null) {
            throw new \InvalidArgumentException('Credential changes require an authenticated global customer.');
        }
        $this->customerId = self::uuid($customerId, 'customerId');
        if ($context->actorId !== $this->customerId) {
            throw new \InvalidArgumentException('Credential actor must match the global customer.');
        }
        $this->credentialKind = self::safeToken($credentialKind, 'credentialKind', 64);
    }
}
