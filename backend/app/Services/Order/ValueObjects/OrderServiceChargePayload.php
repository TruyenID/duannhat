<?php

namespace App\Services\Order\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use InvalidArgumentException;

/**
 * The service charge modelled as its own evidence-carrying line (plan-047 T2.12,
 * issue #1090).
 *
 * A service charge is taxable in exactly the way a product line is, so it needs
 * the same evidence a product line carries: the amount, the rate that was
 * applied, and the resulting tax. Before this existed, `OrderPricingEvidence`
 * held only `serviceChargeMinor` and the tax on it had nowhere to live —
 * folding it into `taxMinor` broke the "tax equals the sum of line evidence"
 * invariant, and leaving it out under-billed the customer by exactly that tax.
 *
 * It is deliberately NOT an `OrderLinePayload`. A product line contributes to
 * `subtotalMinor`; a service charge does not (the persisted
 * `customer_orders.subtotal` column excludes it, and every downstream report
 * reads it that way). Keeping it a separate payload preserves that meaning
 * while still giving the charge its own auditable rate/tax breakdown — which
 * インボイス reporting needs per rate anyway.
 */
final readonly class OrderServiceChargePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /**
     * @param  int  $amountMinor  the charge itself, excluded from subtotalMinor
     * @param  int  $taxAmountMinor  tax ON the charge, counted in the order's taxMinor
     * @param  int|null  $taxRateBasisPoints  rate applied, e.g. 1000 = 10%; null when untaxed
     */
    public function __construct(
        public int $amountMinor,
        public int $taxAmountMinor,
        public ?int $taxRateBasisPoints = null,
    ) {
        if ($amountMinor < 0 || $taxAmountMinor < 0) {
            throw new InvalidArgumentException('Service charge evidence cannot be negative.');
        }

        if ($taxRateBasisPoints !== null && $taxRateBasisPoints < 0) {
            throw new InvalidArgumentException('Service charge tax rate cannot be negative.');
        }

        if ($taxAmountMinor > 0 && ($taxRateBasisPoints === null || $taxRateBasisPoints === 0)) {
            throw new InvalidArgumentException('Service charge tax requires the rate that produced it.');
        }

        if ($amountMinor === 0 && $taxAmountMinor > 0) {
            throw new InvalidArgumentException('A zero service charge cannot carry tax.');
        }
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
