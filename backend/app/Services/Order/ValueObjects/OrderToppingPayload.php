<?php

namespace App\Services\Order\ValueObjects;

use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class OrderToppingPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /** The ToppingGroupItem the customer picked. */
    public string $toppingId;

    /**
     * The concrete ProductSku fulfilling that pick (plan-047 T2.12, #1090).
     * order_item_toppings persists the (topping_group_item_id, product_sku_id)
     * tuple — combo components resolve to real SKUs — so the payload must carry
     * both ids or the insert path would have to re-resolve and could drift.
     */
    public ?string $productSkuId;

    /**
     * #2619 — `$waivedQuantity`: how many of this row's units the group's
     * free_up_to_n strategy waived, resolved by the SAME pricer that produced
     * `$unitPriceMinor` (rows keep full price; the waiver lives at line level).
     * Persisted verbatim into `order_item_toppings.waived_quantity`.
     */
    public function __construct(string $toppingId, public int $quantity, public int $unitPriceMinor, ?string $productSkuId = null, public ?string $note = null, public int $waivedQuantity = 0)
    {
        // unitPriceMinor may be NEGATIVE: discount toppings are a real
        // catalog concept (「値引き」 rows). The line-level floor — a discount
        // can zero a line but never pay the customer — is enforced where the
        // line total is known (ToppingSelectionPricer callers + OrderLineEvidence).
        if ($quantity < 1) {
            throw new InvalidArgumentException('Topping quantity must be positive.');
        }

        if ($waivedQuantity < 0 || $waivedQuantity > $quantity) {
            throw new InvalidArgumentException('Topping waived quantity must stay within 0..quantity.');
        }

        $this->toppingId = MutationCommand::uuid($toppingId, 'toppingId');
        $this->productSkuId = MutationCommand::nullableUuid($productSkuId, 'productSkuId');
    }

    public function jsonSerialize(): array
    {
        return ['topping_id' => $this->toppingId, 'quantity' => $this->quantity, 'unit_price_minor' => $this->unitPriceMinor, 'product_sku_id' => $this->productSkuId, 'note' => $this->note, 'waived_quantity' => $this->waivedQuantity];
    }
}
