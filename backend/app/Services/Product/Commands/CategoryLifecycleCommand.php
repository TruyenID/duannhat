<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Enums\CategoryLifecycleAction;

final readonly class CategoryLifecycleCommand extends MutationCommand
{
    public string $categoryId;

    public string $brandId;

    public function __construct(MutationContext $context, string $categoryId, string $brandId, public CategoryLifecycleAction $action)
    {
        parent::__construct($context);
        $this->categoryId = self::uuid($categoryId, 'categoryId');
        $this->brandId = self::uuid($brandId, 'brandId');
    }

    public function assertAction(CategoryLifecycleAction $expected): void
    {
        if ($this->action !== $expected) {
            throw new \LogicException('Category lifecycle route does not match authorized action.');
        }
    }
}
