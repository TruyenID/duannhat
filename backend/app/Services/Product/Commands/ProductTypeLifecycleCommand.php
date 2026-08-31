<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Enums\ProductTypeLifecycleAction;

final readonly class ProductTypeLifecycleCommand extends MutationCommand
{
    public string $productTypeId;

    public string $brandId;

    public function __construct(MutationContext $context, string $productTypeId, string $brandId, public ProductTypeLifecycleAction $action)
    {
        parent::__construct($context);
        $this->productTypeId = self::uuid($productTypeId, 'productTypeId');
        $this->brandId = self::uuid($brandId, 'brandId');
    }

    public function assertAction(ProductTypeLifecycleAction $expected): void
    {
        if ($this->action !== $expected) {
            throw new \LogicException('Product type lifecycle route does not match authorized action.');
        }
    }
}
