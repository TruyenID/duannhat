<?php

namespace App\Services\Menu\Contracts;

interface MenuQueryPort
{
    public function findById(string $organizationId, string $menuId): ?MenuSnapshot;

    public function findEffectiveForBranch(string $organizationId, string $branchId): ?MenuSnapshot;
}
