<?php

namespace App\Services\Order\ValueObjects;

use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use InvalidArgumentException;

final readonly class OrderLinePayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public string $itemId;

    public string $productId;

    public ?string $skuId;

    /** @var list<OrderToppingPayload> */
    public array $toppings;

    /** @param list<OrderToppingPayload> $toppings */
    public function __construct(string $itemId, string $productId, ?string $skuId, public int $quantity, public int $unitPriceMinor, array $toppings, public ?OrderLineEvidence $evidence = null, public OrderItemStatusEnum $status = OrderItemStatusEnum::Pending, public ?string $note = null, public ?string $startedPreparingAt = null, public ?string $readyAt = null, public ?string $servedAt = null, public ?string $voidedAt = null, public ?string $voidReason = null, public int $refundedQuantity = 0)
    {
        if ($quantity < 1 || $unitPriceMinor < 0 || $refundedQuantity < 0 || $refundedQuantity > $quantity) {
            throw new InvalidArgumentException('Order line quantity and price must be valid.');
        }

        foreach ($toppings as $topping) {
            if (! $topping instanceof OrderToppingPayload) {
                throw new InvalidArgumentException('toppings must contain OrderToppingPayload values.');
            }
        }

        $this->itemId = MutationCommand::uuid($itemId, 'itemId');
        $this->productId = MutationCommand::uuid($productId, 'productId');
        $this->skuId = MutationCommand::nullableUuid($skuId, 'skuId');
        $this->toppings = array_values($toppings);
    }

    public function jsonSerialize(): array
    {
        return [
            'item_id' => $this->itemId,
            'product_id' => $this->productId,
            'sku_id' => $this->skuId,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unitPriceMinor,
            'toppings' => $this->toppings,
            'evidence' => $this->evidence,
            'status' => $this->status->value,
            'note' => $this->note,
            'started_preparing_at' => $this->startedPreparingAt,
            'ready_at' => $this->readyAt,
            'served_at' => $this->servedAt,
            'voided_at' => $this->voidedAt,
            'void_reason' => $this->voidReason,
            'refunded_quantity' => $this->refundedQuantity,
        ];
    }
}
