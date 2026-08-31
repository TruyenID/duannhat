<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class MenuToppingOverridePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $toppingGroupId;

    public string $itemId;

    public ?string $skuId;

    public function __construct(string $toppingGroupId, string $itemId, ?string $skuId, public bool $hidden, public ?int $priceOverrideMinor)
    {
        $this->toppingGroupId = MutationCommand::uuid($toppingGroupId, 'toppingGroupId');
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
        return get_object_vars($this);
    }
}
