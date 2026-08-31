<?php

namespace App\Services\Menu\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class MenuItemPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $productId;

    public ?string $skuId;

    public ?string $taxTypeId;

    public ?string $masterMenuProductId;

    /** @var list<MenuSkuOverridePayload> */
    public array $skuOverrides;

    /** @var list<MenuToppingOverridePayload> */
    public array $toppingOverrides;

    public function __construct(string $productId, ?string $skuId, public int $position, ?string $taxTypeId = null, public bool $active = true, ?string $masterMenuProductId = null, array $skuOverrides = [], array $toppingOverrides = [])
    {
        if ($position < 0) {
            throw new InvalidArgumentException('position cannot be negative.');
        }

        $this->productId = MutationCommand::uuid($productId, 'productId');
        $this->skuId = MutationCommand::nullableUuid($skuId, 'skuId');
        $this->taxTypeId = MutationCommand::nullableUuid($taxTypeId, 'taxTypeId');
        $this->masterMenuProductId = MutationCommand::nullableUuid($masterMenuProductId, 'masterMenuProductId');
        foreach ($skuOverrides as $override) {
            if (! $override instanceof MenuSkuOverridePayload) {
                throw new InvalidArgumentException('skuOverrides must contain MenuSkuOverridePayload values.');
            }
        }
        $this->skuOverrides = MutationCommand::canonicalSet($skuOverrides, static fn (MenuSkuOverridePayload $override): string => $override->skuId, 'skuOverrides');
        foreach ($toppingOverrides as $override) {
            if (! $override instanceof MenuToppingOverridePayload) {
                throw new InvalidArgumentException('toppingOverrides must contain MenuToppingOverridePayload values.');
            }
        }
        $this->toppingOverrides = MutationCommand::canonicalSet($toppingOverrides, static fn (MenuToppingOverridePayload $override): string => $override->toppingGroupId.'|'.$override->itemId.'|'.($override->skuId ?? ''), 'toppingOverrides');
    }

    public function jsonSerialize(): array
    {
        return ['product_id' => $this->productId, 'sku_id' => $this->skuId, 'tax_type_id' => $this->taxTypeId, 'position' => $this->position, 'active' => $this->active, 'master_menu_product_id' => $this->masterMenuProductId, 'sku_overrides' => $this->skuOverrides, 'topping_overrides' => $this->toppingOverrides];
    }
}
