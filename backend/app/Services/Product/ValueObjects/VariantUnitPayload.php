<?php

namespace App\Services\Product\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class VariantUnitPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $unit;

    public string $ratio;

    public string $sku;

    public ?string $barcode;

    public string $price;

    public function __construct(string $unit, string $ratio, string $sku, ?string $barcode, string $price, public bool $base, public bool $sellable)
    {
        $this->unit = MutationCommand::safeToken($unit, 'unit', 50);
        $this->sku = MutationCommand::safeToken($sku, 'sku', 50);
        $this->barcode = $barcode === null ? null : MutationCommand::safeToken($barcode, 'barcode', 100);
        if (! is_numeric($ratio) || (float) $ratio < 0 || ! is_numeric($price) || (float) $price < 0) {
            throw new \InvalidArgumentException('Variant unit ratio and price must be non-negative decimal strings.');
        }
        $this->ratio = $ratio;
        $this->price = $price;
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}
