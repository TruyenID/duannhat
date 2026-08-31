<?php

namespace App\Services\Product\Results;

final readonly class ProductOptionExpansionResult
{
    /** @param list<string> $createdSkuIds */
    public function __construct(public string $optionId, public int $updatedSkuCount, public array $createdSkuIds) {}
}
