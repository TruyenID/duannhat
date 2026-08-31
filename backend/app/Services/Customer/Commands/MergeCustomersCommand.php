<?php

namespace App\Services\Customer\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use InvalidArgumentException;

final readonly class MergeCustomersCommand extends MutationCommand
{
    public string $sourceCustomerId;

    public string $targetCustomerId;

    public string $authorizationEventId;

    public function __construct(
        MutationContext $context,
        string $sourceCustomerId,
        string $targetCustomerId,
        string $authorizationEventId,
    ) {
        parent::__construct($context);
        $this->sourceCustomerId = self::uuid($sourceCustomerId, 'sourceCustomerId');
        $this->targetCustomerId = self::uuid($targetCustomerId, 'targetCustomerId');

        $this->authorizationEventId = self::safeToken($authorizationEventId, 'authorizationEventId', 255);
        if ($context->actorId === null || $this->sourceCustomerId === $this->targetCustomerId) {
            throw new InvalidArgumentException('Customer merge identities and authorization are invalid.');
        }
    }
}
