<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

/** Stamp coupon binding fields onto an order after redemption logic completes. */
final readonly class BindOrderCouponCommand extends MutationCommand
{
    public string $orderId;

    public string $couponId;

    public string $couponCode;

    public float $discountAmount;

    public function __construct(
        MutationContext $context,
        string $orderId,
        string $couponId,
        string $couponCode,
        float $discountAmount,
    ) {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        $this->couponId = self::uuid($couponId, 'couponId');
        $this->couponCode = self::safeToken($couponCode, 'couponCode', 100);
        if ($discountAmount < 0) {
            throw new \InvalidArgumentException('discountAmount cannot be negative.');
        }
        $this->discountAmount = $discountAmount;
    }
}
