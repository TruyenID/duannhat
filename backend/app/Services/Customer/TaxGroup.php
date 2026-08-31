<?php

namespace App\Services\Customer;

/**
 * One per-rate tax group in an order's breakdown (plan-043 §8, インボイス
 * per-rate blocks). `taxable` is the group's net base (after pro-rata coupon
 * discount); `tax` is rounded ONCE for the whole group (端数処理は税率ごとに1回).
 */
final readonly class TaxGroup
{
    public function __construct(
        public float $rate,
        public float $taxable,
        public float $tax,
    ) {}

    /** @return array{rate: float, taxable: float, tax: float} */
    public function toArray(): array
    {
        return ['rate' => $this->rate, 'taxable' => $this->taxable, 'tax' => $this->tax];
    }
}
