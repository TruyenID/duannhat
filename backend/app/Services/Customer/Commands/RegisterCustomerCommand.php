<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\Enums\CustomerScopeKind;
use App\Services\Customer\ValueObjects\CustomerScopeEvidence;
use App\Services\Customer\ValueObjects\TenantCustomerProfilePayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class RegisterCustomerCommand extends MutationCommand
{
    public string $customerId;

    public ?string $branchId;

    public ?string $brandId;

    public string $profileFingerprint;

    public function __construct(MutationContext $context, string $customerId, string $branchId, string $brandId, public TenantCustomerProfilePayload $payload, string $profileFingerprint, public CustomerScopeEvidence $scope)
    {
        parent::__construct($context);
        $this->customerId = self::uuid($customerId, 'customerId');
        $this->branchId = self::uuid($branchId, 'branchId');
        $this->brandId = self::uuid($brandId, 'brandId');
        if ($scope->kind !== CustomerScopeKind::TenantCrm || $context->organizationId === null || $scope->organizationId !== $context->organizationId || $scope->brandId !== $this->brandId || $scope->branchId !== $this->branchId) {
            throw new \InvalidArgumentException('Customer scope evidence must match command and mutation context.');
        }
        $this->profileFingerprint = self::verifiedFingerprint($profileFingerprint, 'profileFingerprint', $payload);
    }
}
