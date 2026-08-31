<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Enums\ProductLifecycleAction;

final readonly class ProductLifecycleCommand extends MutationCommand
{
    public string $productId;

    public string $brandId;

    public ?string $reason;

    public function __construct(MutationContext $context, string $productId, string $brandId, public ProductLifecycleAction $action, ?string $reason = null)
    {
        parent::__construct($context);
        $this->productId = self::uuid($productId, 'productId');
        $this->brandId = self::uuid($brandId, 'brandId');
        if ($action === ProductLifecycleAction::Reject && ($reason === null || trim($reason) === '')) {
            throw new \InvalidArgumentException('reason is required when rejecting a product.');
        }
        $this->reason = $reason === null ? null : self::safeToken($reason, 'reason', 1000);
    }

    public function assertAction(ProductLifecycleAction $expected): void
    {
        if ($this->action !== $expected) {
            throw new \LogicException('Product lifecycle route does not match authorized action.');
        }
    }
}
