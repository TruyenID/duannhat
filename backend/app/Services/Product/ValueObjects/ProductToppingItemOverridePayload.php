<?php

namespace App\Services\Product\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class ProductToppingItemOverridePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $itemId;

    public ?string $skuId;

    public function __construct(string $itemId, ?string $skuId, public bool $hidden, public ?int $priceOverrideMinor)
    {
        $this->itemId = MutationCommand::uuid($itemId, 'itemId');
        $this->skuId = MutationCommand::nullableUuid($skuId, 'skuId');
        if ($priceOverrideMinor !== null && $priceOverrideMinor < 0) {
            throw new \InvalidArgumentException('priceOverrideMinor cannot be negative.');
        }
        if ($hidden && $priceOverrideMinor !== null) {
            throw new \InvalidArgumentException('A hidden topping cannot also publish a price override.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['item_id' => $this->itemId, 'sku_id' => $this->skuId, 'hidden' => $this->hidden, 'price_override_minor' => $this->priceOverrideMinor];
    }
}
