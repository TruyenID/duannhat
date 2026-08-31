<?php

namespace App\Services\Product\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class ProductToppingGroupPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $toppingGroupId;

    public ?string $skuId;

    /** @var list<ProductToppingItemOverridePayload> */
    public array $itemOverrides;

    public function __construct(string $toppingGroupId, ?string $skuId, public int $position, public bool $active = true, public ?int $minimumSelections = null, public ?int $maximumSelections = null, array $itemOverrides = [])
    {
        $this->toppingGroupId = MutationCommand::uuid($toppingGroupId, 'toppingGroupId');
        $this->skuId = MutationCommand::nullableUuid($skuId, 'skuId');
        if ($position < 0) {
            throw new \InvalidArgumentException('position cannot be negative.');
        }
        if (($minimumSelections !== null && $minimumSelections < 0) || ($maximumSelections !== null && $maximumSelections < 0) || ($minimumSelections !== null && $maximumSelections !== null && $minimumSelections > $maximumSelections)) {
            throw new \InvalidArgumentException('Topping selection bounds are invalid.');
        }
        foreach ($itemOverrides as $override) {
            if (! $override instanceof ProductToppingItemOverridePayload) {
                throw new \InvalidArgumentException('itemOverrides must contain ProductToppingItemOverridePayload values.');
            }
        }
        $this->itemOverrides = MutationCommand::canonicalSet($itemOverrides, static fn (ProductToppingItemOverridePayload $override): string => $override->itemId.'|'.($override->skuId ?? ''), 'itemOverrides');
    }

    public function jsonSerialize(): array
    {
        return ['topping_group_id' => $this->toppingGroupId, 'sku_id' => $this->skuId, 'position' => $this->position, 'active' => $this->active, 'minimum_selections' => $this->minimumSelections, 'maximum_selections' => $this->maximumSelections, 'item_overrides' => $this->itemOverrides];
    }
}
