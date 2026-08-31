<?php

namespace App\Services\Product\Contracts;

interface ProductQueryPort
{
    public function findById(string $organizationId, string $productId): ?ProductSnapshot;

    public function findForOrderSnapshot(string $organizationId, string $productId): ?ProductSnapshot;
}
