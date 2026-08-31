<?php

namespace App\Services\Order\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class OrderToppingSelectionPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $toppingGroupItemId;

    public string $productSkuId;

    public function __construct(string $toppingGroupItemId, string $productSkuId, public int $quantity, public ?string $note = null)
    {
        $this->toppingGroupItemId = MutationCommand::uuid($toppingGroupItemId, 'toppingGroupItemId');
        $this->productSkuId = MutationCommand::uuid($productSkuId, 'productSkuId');

        if ($quantity < 1) {
            throw new InvalidArgumentException('Topping selection quantity must be positive.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['topping_group_item_id' => $this->toppingGroupItemId, 'product_sku_id' => $this->productSkuId, 'quantity' => $this->quantity, 'note' => $this->note];
    }
}
