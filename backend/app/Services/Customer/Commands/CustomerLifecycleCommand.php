<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\Enums\CustomerLifecycleAction;
use App\Services\Customer\Enums\CustomerScopeKind;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class CustomerLifecycleCommand extends MutationCommand
{
    public string $customerId;

    public ?string $reason;

    public string $authorityReference;

    public function __construct(MutationContext $context, string $customerId, public CustomerScopeKind $scope, public CustomerLifecycleAction $action, string $authorityReference, ?string $reason = null)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->customerId = self::uuid($customerId, 'customerId');
        if ($context->actorId === null) {
            throw new \InvalidArgumentException('Customer lifecycle changes require an authenticated actor.');
        }
        if ($scope === CustomerScopeKind::GlobalAccount && ($context->organizationId !== null || $context->actorId !== $this->customerId)) {
            throw new \InvalidArgumentException('Global lifecycle changes require the authenticated customer as actor.');
        }
        if ($scope === CustomerScopeKind::TenantCrm && $context->organizationId === null) {
            throw new \InvalidArgumentException('Tenant lifecycle changes require tenant context.');
        }
        $this->authorityReference = self::safeToken($authorityReference, 'authorityReference', 255);
        $this->reason = $reason === null ? null : self::safeToken($reason, 'reason', 500);
    }
}
