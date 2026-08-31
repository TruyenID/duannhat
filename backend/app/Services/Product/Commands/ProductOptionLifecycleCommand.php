<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Enums\ProductOptionLifecycleAction;

final readonly class ProductOptionLifecycleCommand extends MutationCommand
{
    public string $optionId;

    public string $brandId;

    public function __construct(MutationContext $context, string $optionId, string $brandId, public ProductOptionLifecycleAction $action)
    {
        parent::__construct($context);
        $this->optionId = self::uuid($optionId, 'optionId');
        $this->brandId = self::uuid($brandId, 'brandId');
    }

    public function assertAction(ProductOptionLifecycleAction $expected): void
    {
        if ($this->action !== $expected) {
            throw new \LogicException('Option lifecycle route does not match authorized action.');
        }
    }
}
