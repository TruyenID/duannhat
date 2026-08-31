<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Enums\ProductOptionValueLifecycleAction;

final readonly class ProductOptionValueLifecycleCommand extends MutationCommand
{
    public string $valueId;

    public string $brandId;

    public function __construct(MutationContext $context, string $valueId, string $brandId, public ProductOptionValueLifecycleAction $action)
    {
        parent::__construct($context);
        $this->valueId = self::uuid($valueId, 'valueId');
        $this->brandId = self::uuid($brandId, 'brandId');
    }

    public function assertAction(ProductOptionValueLifecycleAction $expected): void
    {
        if ($this->action !== $expected) {
            throw new \LogicException('Option-value lifecycle route does not match authorized action.');
        }
    }
}
