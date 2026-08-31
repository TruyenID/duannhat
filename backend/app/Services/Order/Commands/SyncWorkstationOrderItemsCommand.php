<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Upsert workstation-synced order lines with server-resolved unit prices. */
final readonly class SyncWorkstationOrderItemsCommand extends MutationCommand
{
    public string $orderId;

    /** @var array<int, array<string, mixed>> */
    public array $items;

    /** @var array<int, float> */
    public array $authoritativePrices;

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, float>  $authoritativePrices
     */
    public function __construct(
        MutationContext $context,
        string $orderId,
        array $items,
        array $authoritativePrices,
    ) {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        if ($items === []) {
            throw new \InvalidArgumentException('items cannot be empty.');
        }
        $this->items = $items;
        $this->authoritativePrices = $authoritativePrices;
    }
}
