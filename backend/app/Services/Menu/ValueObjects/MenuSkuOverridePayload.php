<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class MenuSkuOverridePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $skuId;

    public function __construct(string $skuId, public int $sellingPriceMinor, public bool $priceOverridden, public bool $active)
    {
        $this->skuId = MutationCommand::uuid($skuId, 'skuId');
        if ($sellingPriceMinor < 0) {
            throw new \InvalidArgumentException('sellingPriceMinor cannot be negative.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['sku_id' => $this->skuId, 'selling_price_minor' => $this->sellingPriceMinor, 'price_overridden' => $this->priceOverridden, 'active' => $this->active];
    }
}
