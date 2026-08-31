<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/**
 * Apply a coupon code to an order through the legacy single writer
 * (CouponService::apply): freshness + counter limits + branch eligibility +
 * per-customer caps + the plan-019 exclusive-promotion downgrade all live
 * there. The workstation transport has its own pre-discounted command
 * ({@see ApplyWorkstationOrderCouponCommand}); this one serves the POS and
 * customer-web transports, which let Cloud compute the discount.
 */
final readonly class ApplyOrderCouponCommand extends MutationCommand
{
    private const VIAS = ['pos', 'customer_web'];

    public string $orderId;

    public string $couponCode;

    public ?string $customerId;

    public string $via;

    public function __construct(
        MutationContext $context,
        string $orderId,
        string $couponCode,
        ?string $customerId = null,
        string $via = 'pos',
        public bool $downgradeExclusivePromotions = false,
    ) {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->couponCode = self::safeToken($couponCode, 'couponCode', 100);
        $this->customerId = self::nullableUuid($customerId, 'customerId');

        if (! in_array($via, self::VIAS, true)) {
            throw new \InvalidArgumentException('via must be one of: '.implode(', ', self::VIAS).'.');
        }
        $this->via = $via;
    }
}
