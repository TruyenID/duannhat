<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Services\Payment\Gateway\ValueObjects\GatewayValue;
use App\Services\Payment\Policy\Enums\BranchManagementModel;
use InvalidArgumentException;

final readonly class BranchManagementProjection extends GatewayValue
{
    public string $organizationId;

    public string $branchOrgUnitId;

    public string $identityBrandId;

    public ?string $brandOwnerOrgUnitId;

    public ?string $operatorOrgUnitId;

    public ?string $ownershipRevision;

    public string $reason;

    public function __construct(
        string $organizationId,
        string $branchOrgUnitId,
        string $identityBrandId,
        public BranchManagementModel $managementModel,
        ?string $brandOwnerOrgUnitId,
        ?string $operatorOrgUnitId,
        ?string $ownershipRevision,
        string $reason,
    ) {
        $this->organizationId = self::uuid($organizationId, 'organizationId');
        $this->branchOrgUnitId = self::externalId($branchOrgUnitId, 'branchOrgUnitId');
        $this->identityBrandId = self::uuid($identityBrandId, 'identityBrandId');
        $this->brandOwnerOrgUnitId = $brandOwnerOrgUnitId === null
            ? null
            : self::uuid($brandOwnerOrgUnitId, 'brandOwnerOrgUnitId');
        $this->operatorOrgUnitId = $operatorOrgUnitId === null
            ? null
            : self::uuid($operatorOrgUnitId, 'operatorOrgUnitId');
        $this->ownershipRevision = $ownershipRevision === null
            ? null
            : OpaqueOwnershipRevision::validate($ownershipRevision);
        $this->reason = self::requestKey($reason, 'reason');

        if ($managementModel !== BranchManagementModel::Unresolved
            && ($this->brandOwnerOrgUnitId === null
                || $this->operatorOrgUnitId === null
                || $this->ownershipRevision === null)) {
            throw new InvalidArgumentException('Resolved ownership requires owner, operator, and opaque revision evidence.');
        }

        if ($managementModel === BranchManagementModel::HqManaged
            && $this->brandOwnerOrgUnitId !== $this->operatorOrgUnitId) {
            throw new InvalidArgumentException('HQ-managed ownership requires the brand owner to be the legal operator.');
        }
    }

    public static function unresolved(BranchManagementLookup $lookup, string $reason): self
    {
        return new self(
            $lookup->organizationId,
            $lookup->branchOrgUnitId,
            $lookup->identityBrandId,
            BranchManagementModel::Unresolved,
            null,
            null,
            null,
            $reason,
        );
    }

    public function ownerScope(): ?PaymentConnectionOwnerScopeEnum
    {
        return match ($this->managementModel) {
            BranchManagementModel::HqManaged => PaymentConnectionOwnerScopeEnum::Hq,
            BranchManagementModel::FranchiseOwned => PaymentConnectionOwnerScopeEnum::Franchise,
            BranchManagementModel::Unresolved => null,
        };
    }

    public function matches(BranchManagementLookup $lookup): bool
    {
        return $this->organizationId === $lookup->organizationId
            && $this->branchOrgUnitId === $lookup->branchOrgUnitId
            && $this->identityBrandId === $lookup->identityBrandId;
    }
}
