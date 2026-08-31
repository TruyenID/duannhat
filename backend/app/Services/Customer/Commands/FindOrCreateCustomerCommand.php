<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\Enums\CustomerScopeKind;
use App\Services\Customer\ValueObjects\CustomerScopeEvidence;
use App\Services\Customer\ValueObjects\TenantCustomerProfilePayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class FindOrCreateCustomerCommand extends MutationCommand
{
    public string $candidateCustomerId;

    public string $profileFingerprint;

    public function __construct(MutationContext $context, string $candidateCustomerId, public TenantCustomerProfilePayload $payload, public CustomerScopeEvidence $scope, string $profileFingerprint)
    {
        parent::__construct($context);
        $this->candidateCustomerId = self::uuid($candidateCustomerId, 'candidateCustomerId');
        if ($scope->kind !== CustomerScopeKind::TenantCrm || $context->organizationId === null || $scope->organizationId !== $context->organizationId) {
            throw new \InvalidArgumentException('Customer lookup scope must match mutation context.');
        } $this->profileFingerprint = self::verifiedFingerprint($profileFingerprint, 'profileFingerprint', $payload);
    }
}
