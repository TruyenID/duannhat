<?php

namespace App\Services\Customer\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class RevokeCustomerAccessTokenCommand extends MutationCommand
{
    public string $customerId;

    public string $tokenId;

    public function __construct(MutationContext $context, string $customerId, string $tokenId, public bool $revokeOtherTokens = false)
    {
        parent::__construct($context);
        $this->customerId = self::uuid($customerId, 'customerId');
        $this->tokenId = self::safeToken($tokenId, 'tokenId', 255);
        if ($context->organizationId !== null || $context->actorId !== $this->customerId) {
            throw new \InvalidArgumentException('Token revocation requires the authenticated global customer.');
        }
    }
}
