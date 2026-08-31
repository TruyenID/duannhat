<?php

namespace App\Services\Product\ValueObjects;

final readonly class CatalogSkuProjection
{
    public function __construct(
        public string $skuId,
        public string $sellingPrice,
    ) {}
}
