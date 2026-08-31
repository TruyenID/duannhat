<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class InitializeOrderCommand extends MutationCommand
{
    public string $orderId;

    /** @var list<string> */
    public array $tableIds;

    /**
     * @param  list<string>  $tableIds  first-write-wins table binding (legacy initOrder)
     * @param  int|null  $guestCount  first-write-wins guest count
     */
    public function __construct(MutationContext $context, string $orderId, array $tableIds = [], public ?int $guestCount = null)
    {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->tableIds = self::canonicalSet(
            array_map(static fn (string $id): string => self::uuid($id, 'tableId'), array_values($tableIds)),
            static fn (string $id): string => $id,
            'tableIds',
        );
        if ($guestCount !== null && $guestCount < 1) {
            throw new \InvalidArgumentException('guestCount must be positive when supplied.');
        }
    }
}
