<?php

namespace App\Services\Product\Results;

final readonly class ProductOptionSyncResult
{
    /** @param list<string> $createdSkuIds */
    public function __construct(public string $optionId, public array $createdSkuIds) {}
}
