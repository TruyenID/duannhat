<?php

namespace App\Services\Product\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Product\Enums\VariantUnitLifecycleAction;

final readonly class VariantUnitLifecycleCommand extends MutationCommand
{
    public string $unitId;

    public string $brandId;

    public function __construct(MutationContext $context, string $unitId, string $brandId, public VariantUnitLifecycleAction $action)
    {
        parent::__construct($context);
        $this->unitId = self::uuid($unitId, 'unitId');
        $this->brandId = self::uuid($brandId, 'brandId');
    }

    public function assertAction(VariantUnitLifecycleAction $expected): void
    {
        if ($this->action !== $expected) {
            throw new \LogicException('Variant-unit lifecycle route does not match authorized action.');
        }
    }
}
