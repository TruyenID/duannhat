<?php

namespace App\Services\Customer\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class CustomerLinkagePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public ?string $brandId;

    public ?string $branchId;

    public function __construct(?string $brandId, ?string $branchId)
    {
        $this->brandId = MutationCommand::nullableUuid($brandId, 'brandId');
        $this->branchId = MutationCommand::nullableUuid($branchId, 'branchId');
    }

    public function jsonSerialize(): array
    {
        return ['brand_id' => $this->brandId, 'branch_id' => $this->branchId];
    }
}
