<?php

namespace App\Services\Customer\Commands;

use App\Services\Customer\ValueObjects\CustomerLinkagePayload;
use App\Services\Customer\ValueObjects\CustomerScopeEvidence;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class UnlinkCustomerScopeCommand extends MutationCommand
{
    public string $customerId;

    public string $linkageFingerprint;

    public function __construct(MutationContext $context, string $customerId, public CustomerLinkagePayload $payload, public CustomerScopeEvidence $scope, string $linkageFingerprint)
    {
        parent::__construct($context);
        $this->customerId = self::uuid($customerId, 'customerId');
        if ($scope->organizationId !== $context->organizationId || $scope->brandId !== $payload->brandId || $scope->branchId !== $payload->branchId || $scope->authorizedById === null || $scope->authorizedById !== $context->actorId) {
            throw new \InvalidArgumentException('Customer unlink requires matching verified nesting, context, and authorization provenance.');
        } $this->linkageFingerprint = self::verifiedFingerprint($linkageFingerprint, 'linkageFingerprint', $payload);
    }
}
