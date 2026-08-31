<?php

namespace App\Services\Payment\Policy;

use App\Services\Payment\Policy\Contracts\BranchManagementProjectionSource;
use App\Services\Payment\Policy\ValueObjects\BranchManagementLookup;
use App\Services\Payment\Policy\ValueObjects\BranchManagementProjection;

/** Test/dev override keyed by branch org-unit id (console_branch_id). */
final class InMemoryBranchManagementProjectionSource implements BranchManagementProjectionSource
{
    /** @param array<string, BranchManagementProjection> $projections */
    public function __construct(private array $projections = []) {}

    public function resolve(BranchManagementLookup $lookup): BranchManagementProjection
    {
        return $this->projections[$lookup->branchOrgUnitId]
            ?? BranchManagementProjection::unresolved($lookup, 'ownership_source_unavailable');
    }

    public function register(string $branchOrgUnitId, BranchManagementProjection $projection): void
    {
        $this->projections[$branchOrgUnitId] = $projection;
    }
}
