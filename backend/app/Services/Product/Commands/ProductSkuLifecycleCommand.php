<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Enums\ProductSkuLifecycleAction;

final readonly class ProductSkuLifecycleCommand extends MutationCommand
{
    public string $skuId;

    public string $brandId;

    public function __construct(MutationContext $context, string $skuId, string $brandId, public ProductSkuLifecycleAction $action)
    {
        parent::__construct($context);
        $this->skuId = self::uuid($skuId, 'skuId');
        $this->brandId = self::uuid($brandId, 'brandId');
    }

    public function assertAction(ProductSkuLifecycleAction $expected): void
    {
        if ($this->action !== $expected) {
            throw new \LogicException('SKU lifecycle route does not match authorized action.');
        }
    }
}
