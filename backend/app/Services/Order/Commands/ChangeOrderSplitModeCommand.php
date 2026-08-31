<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Enums\OrderSplitMode;

final readonly class ChangeOrderSplitModeCommand extends MutationCommand
{
    public string $orderId;

    public function __construct(MutationContext $context, string $orderId, public ?OrderSplitMode $splitMode, public ?int $peopleCount = null)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        if ($peopleCount !== null && $peopleCount < 2) {
            throw new \InvalidArgumentException('split_people_count must be at least 2 when supplied.');
        }
        if ($splitMode !== OrderSplitMode::Even && $peopleCount !== null) {
            throw new \InvalidArgumentException('split_people_count is only valid when split_mode is even.');
        }
    }
}
