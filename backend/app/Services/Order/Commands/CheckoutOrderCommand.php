<?php

namespace App\Services\Order\Commands;

use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\MutationContext;

final readonly class CheckoutOrderCommand extends MutationCommand
{
    public string $orderId;

    /**
     * @param  float|null  $discountAmount  manual checkout discount in MAJOR
     *                                      units — deliberately the legacy contract, because this command
     *                                      bridges straight into WritesCustomerOrders::checkout() whose
     *                                      decimal(15,2) column is major-unit. Null keeps the order's current
     *                                      discount, exactly as the legacy array path does.
     */
    /**
     * @param  string|null  $discountReason  #1124 — mandatory whenever the manual
     *                                       discount is > 0; the domain writer enforces the requiredness so
     *                                       every route shares the rule.
     */
    public function __construct(
        MutationContext $context,
        string $orderId,
        public ?float $discountAmount = null,
        public ?string $discountReason = null,
    ) {
        parent::__construct($context);
        self::requireExpectedVersion($context);
        $this->orderId = self::uuid($orderId, 'orderId');
        if ($discountAmount !== null && $discountAmount < 0) {
            throw new \InvalidArgumentException('discountAmount cannot be negative.');
        }
    }
}
