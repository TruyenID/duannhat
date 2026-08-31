<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** First-write-wins kitchen timestamp stamp (started_preparing_at or ready_at). */
final readonly class StampKitchenItemTimestampCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    public string $column;

    public function __construct(MutationContext $context, string $orderId, string $itemId, string $column)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        if (! in_array($column, ['started_preparing_at', 'ready_at'], true)) {
            throw new \InvalidArgumentException('column must be started_preparing_at or ready_at.');
        }
        $this->column = $column;
    }
}
