<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/**
 * Restore promoted lines to their original price when an exclusive promotion
 * cannot coexist with the coupon being applied (#1564, epic #962).
 *
 * Why this command exists at all: the logic used to live in
 * `App\Services\Promotion\CouponService::downgradeExclusivePromotions()`, which
 * reached `EloquentOrderPersistence::patchOrderItemUnchecked()` **through the
 * container** — `app(EloquentOrderPersistence::class)`. That is Pricing writing
 * order-item prices directly through an `Internal` class, bypassing
 * `OrderMutationFacade`, on the money path, via a method whose own name says
 * `Unchecked`.
 *
 * The comment left at that accessor records how it got there: an earlier change
 * called `$this->orderPersistence()` before the accessor existed, so the method
 * threw at runtime, and the fix was to resolve from the container rather than to
 * publish an API for it. A bypass added to repair a bypass.
 */
final readonly class DowngradeExclusivePromotionsCommand extends MutationCommand
{
    public string $orderId;

    public ?string $userId;

    public function __construct(
        MutationContext $context,
        string $orderId,
        ?string $userId = null,
    ) {
        parent::__construct($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->userId = $userId === null ? null : self::uuid($userId, 'userId');
    }
}
