<?php

namespace App\Services\Order\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class OrderLineSelectionPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $lineId;

    /**
     * The explicit menu line the customer tapped. Nullable (#1090 phase 2):
     * POS/Handy clients may send only the product SKU, in which case the
     * resolver applies the deterministic #514 rule — this branch's LOWEST
     * active menu price, tie-broken by id — or, for a SKU on no menu at all,
     * the SKU's own selling price. Exactly the legacy addItems contract.
     */
    public ?string $menuProductSkuId;

    /** The product-anchored alternative when no explicit menu line is sent. */
    public ?string $productSkuId;

    /** @var list<OrderToppingSelectionPayload> */
    public array $toppings;

    public function __construct(string $lineId, ?string $menuProductSkuId, public int $quantity, array $toppings = [], public ?string $note = null, ?string $productSkuId = null)
    {
        $this->lineId = MutationCommand::uuid($lineId, 'lineId');
        $this->menuProductSkuId = MutationCommand::nullableUuid($menuProductSkuId, 'menuProductSkuId');
        $this->productSkuId = MutationCommand::nullableUuid($productSkuId, 'productSkuId');

        if ($this->menuProductSkuId === null && $this->productSkuId === null) {
            throw new InvalidArgumentException('An order line needs a menu line or a product SKU to anchor on.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('Order line selection quantity must be positive.');
        }

        foreach ($toppings as $topping) {
            if (! $topping instanceof OrderToppingSelectionPayload) {
                throw new InvalidArgumentException('toppings must contain OrderToppingSelectionPayload values.');
            }
        }

        $this->toppings = MutationCommand::canonicalSet(
            $toppings,
            static fn (OrderToppingSelectionPayload $topping): string => $topping->toppingGroupItemId.'|'.$topping->productSkuId,
            'toppings',
        );
    }

    public function jsonSerialize(): array
    {
        return ['line_id' => $this->lineId, 'menu_product_sku_id' => $this->menuProductSkuId, 'product_sku_id' => $this->productSkuId, 'quantity' => $this->quantity, 'toppings' => $this->toppings, 'note' => $this->note];
    }
}
