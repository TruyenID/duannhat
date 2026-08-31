<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class GenerateProductSkuCombinationsCommand extends MutationCommand
{
    public string $productId;

    public string $brandId;

    public function __construct(MutationContext $context, string $productId, string $brandId)
    {
        parent::__construct($context);
        $this->productId = self::uuid($productId, 'productId');
        $this->brandId = self::uuid($brandId, 'brandId');
    }
}
