<?php

namespace App\Services\Customer;

/**
 * Immutable output of the plan-043 pricing engine (§8) for one order.
 *
 * `taxAmount` = Σ per-rate group tax + service-charge tax (projected from the
 * order_conditions tax rows). In included mode the group taxes are
 * "内税" (already inside the prices) so they are NOT added again into
 * `totalAmount` — see OrderPricingCalculator::priceGroups.
 */
final readonly class PricingResult
{
    /**
     * @param  TaxGroup[]  $groups  per-rate breakdown (service-charge tax merged into its rate group)
     */
    public function __construct(
        public float $subtotal,
        public float $discount,
        public float $taxableBase,
        public array $groups,
        public float $serviceCharge,
        public float $serviceChargeTax,
        public float $taxAmount,
        public float $totalAmount,
        public bool $pricesIncludeTax,
    ) {}

    /** @return array<int, array{rate: float, taxable: float, tax: float}> */
    public function groupsToArray(): array
    {
        return array_map(fn (TaxGroup $g) => $g->toArray(), $this->groups);
    }
}
