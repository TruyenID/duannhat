<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/**
 * #1074 — bulk-assign a tax type to every product in a category.
 *
 * `taxTypeId = null` clears the per-product override so the products fall
 * back to inheritance (branch/brand default).
 */
final readonly class AssignCategoryTaxTypeCommand extends MutationCommand
{
    public string $categoryId;

    public string $brandId;

    public ?string $taxTypeId;

    public function __construct(MutationContext $context, string $categoryId, string $brandId, ?string $taxTypeId)
    {
        parent::__construct($context);
        $this->categoryId = self::uuid($categoryId, 'categoryId');
        $this->brandId = self::uuid($brandId, 'brandId');
        $this->taxTypeId = $taxTypeId === null ? null : self::uuid($taxTypeId, 'taxTypeId');
    }
}
