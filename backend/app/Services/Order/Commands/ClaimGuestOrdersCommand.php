<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class ClaimGuestOrdersCommand extends MutationCommand
{
    public string $customerId;

    /** @var array<int, string> */
    public array $orderIds;

    /**
     * @param  array<int, string>  $orderIds
     */
    public function __construct(MutationContext $context, string $customerId, array $orderIds)
    {
        parent::__construct($context);
        $this->customerId = self::uuid($customerId, 'customerId');
        $this->orderIds = array_map(
            fn (string $id) => self::uuid($id, 'orderIds[]'),
            $orderIds,
        );
    }
}
