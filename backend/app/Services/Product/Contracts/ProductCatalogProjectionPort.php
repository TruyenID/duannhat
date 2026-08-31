<?php

namespace App\Services\Product\Contracts;

use App\Services\Product\ValueObjects\CatalogSkuProjection;

interface ProductCatalogProjectionPort
{
    /** @param list<CatalogSkuProjection> $newSkus */
    public function syncNewSkusToMenuBranches(string $productId, array $newSkus): void;
}
