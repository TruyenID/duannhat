<?php

namespace App\Services\Customer\ValueObjects;

use App\Services\Customer\Enums\CustomerScopeKind;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class CustomerScopeEvidence implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public ?string $organizationId;

    public ?string $brandId;

    public ?string $branchId;

    public ?string $brandOrganizationId;

    public ?string $branchBrandId;

    public ?string $authorizedById;

    public ?string $authorizationEventId;

    public function __construct(public CustomerScopeKind $kind, ?string $organizationId, ?string $brandId, ?string $branchId, ?string $brandOrganizationId, ?string $branchBrandId, ?string $authorizedById = null, ?string $authorizationEventId = null)
    {
        $this->organizationId = MutationCommand::nullableUuid($organizationId, 'organizationId');
        $this->brandId = MutationCommand::nullableUuid($brandId, 'brandId');
        $this->branchId = MutationCommand::nullableUuid($branchId, 'branchId');
        $this->brandOrganizationId = MutationCommand::nullableUuid($brandOrganizationId, 'brandOrganizationId');
        $this->branchBrandId = MutationCommand::nullableUuid($branchBrandId, 'branchBrandId');
        $this->authorizedById = MutationCommand::nullableUuid($authorizedById, 'authorizedById');
        $this->authorizationEventId = $authorizationEventId === null ? null : MutationCommand::safeToken($authorizationEventId, 'authorizationEventId', 255);
        if (($this->authorizedById === null) !== ($this->authorizationEventId === null)) {
            throw new \InvalidArgumentException('Customer scope authorization provenance must be complete.');
        }
        if ($kind === CustomerScopeKind::GlobalAccount && array_filter([$this->organizationId, $this->brandId, $this->branchId, $this->brandOrganizationId, $this->branchBrandId]) !== []) {
            throw new \InvalidArgumentException('Global customer scope cannot carry tenant identifiers.');
        }
        if ($kind === CustomerScopeKind::TenantCrm && ($this->organizationId === null || $this->brandId === null || $this->branchId === null)) {
            throw new \InvalidArgumentException('Tenant CRM scope requires organization, brand, and branch identifiers.');
        }
        if (($this->brandId === null) !== ($this->brandOrganizationId === null) || ($this->branchId === null) !== ($this->branchBrandId === null)) {
            throw new \InvalidArgumentException('Scope nesting evidence must be complete.');
        }
        if ($this->brandOrganizationId !== null && $this->brandOrganizationId !== $this->organizationId) {
            throw new \InvalidArgumentException('Brand must belong to the mutation organization.');
        }
        if ($this->branchBrandId !== null && $this->branchBrandId !== $this->brandId) {
            throw new \InvalidArgumentException('Branch must belong to the selected brand.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
