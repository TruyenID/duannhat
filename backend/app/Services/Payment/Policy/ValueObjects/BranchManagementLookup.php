<?php

namespace App\Services\Payment\Policy\ValueObjects;

use App\Services\Payment\Gateway\ValueObjects\GatewayValue;
use DateTimeImmutable;

final readonly class BranchManagementLookup extends GatewayValue
{
    public string $organizationId;

    public string $identityBrandId;

    public string $branchOrgUnitId;

    public function __construct(
        string $organizationId,
        string $identityBrandId,
        string $branchOrgUnitId,
        public DateTimeImmutable $at,
    ) {
        $this->organizationId = self::uuid($organizationId, 'organizationId');
        $this->identityBrandId = self::uuid($identityBrandId, 'identityBrandId');
        $this->branchOrgUnitId = self::externalId($branchOrgUnitId, 'branchOrgUnitId');
    }
}
