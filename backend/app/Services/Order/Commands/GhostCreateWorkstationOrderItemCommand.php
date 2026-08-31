<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Materialise a missing order line from a KDS bump snapshot before status advance. */
final readonly class GhostCreateWorkstationOrderItemCommand extends MutationCommand
{
    public string $orderId;

    public string $itemId;

    /** @var array{product_sku_id: string, quantity?: int, unit_price?: int, note?: ?string} */
    public array $snapshot;

    /**
     * @param  array{product_sku_id: string, quantity?: int, unit_price?: int, note?: ?string}  $snapshot
     */
    public function __construct(MutationContext $context, string $orderId, string $itemId, array $snapshot)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->itemId = self::uuid($itemId, 'itemId');
        $this->snapshot = [
            'product_sku_id' => self::uuid($snapshot['product_sku_id'] ?? '', 'product_sku_id'),
            'quantity' => isset($snapshot['quantity']) ? (int) $snapshot['quantity'] : 1,
            'unit_price' => isset($snapshot['unit_price']) ? (int) $snapshot['unit_price'] : 0,
            'note' => $snapshot['note'] ?? null,
        ];
    }
}
