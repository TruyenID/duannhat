<?php

namespace App\Services\Product\Results;

final readonly class CategoryTaxTypeAssignmentResult
{
    public function __construct(
        public string $categoryId,
        public ?string $taxTypeId,
        public int $updated,
    ) {}
}
