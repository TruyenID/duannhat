<?php

namespace App\Services\Order\ValueObjects;

use App\Omnify\Enums\CustomerOrderPickupTypeEnum;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Order\Enums\OrderChannel;
use App\Services\Order\Enums\OrderSplitMode;
use InvalidArgumentException;

final readonly class OrderDraftPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /** @var list<OrderLinePayload> */
    public array $lines;

    public ?string $note;

    public ?string $customerId;

    public ?string $deviceId;

    public ?string $couponId;

    public SupportedLocale $locale;

    /** @var list<string> */
    public array $tableIds;

    /** @param list<OrderLinePayload> $lines */
    public function __construct(array $lines, ?string $note = null, public ?string $externalOrderId = null, public ?string $clientOrderId = null, public CustomerOrderTypeEnum $orderType = CustomerOrderTypeEnum::Spot, public CustomerOrderPickupTypeEnum $pickupType = CustomerOrderPickupTypeEnum::Immediate, public ?string $scheduledPickupAt = null, public ?OrderContactPayload $contact = null, ?string $customerId = null, public ?int $guestCount = null, array $tableIds = [], SupportedLocale|string $locale = SupportedLocale::Japanese, public OrderChannel $channel = OrderChannel::Api, ?string $deviceId = null, ?string $couponId = null, public ?string $couponCode = null, public CustomerOrderStatusEnum $status = CustomerOrderStatusEnum::Open, public ?OrderSplitMode $splitMode = null, public ?int $splitPeopleCount = null, public ?OrderPricingEvidence $pricingEvidence = null, public ?OrderServiceChargePayload $serviceCharge = null)
    {
        foreach ($lines as $line) {
            if (! $line instanceof OrderLinePayload) {
                throw new InvalidArgumentException('lines must contain OrderLinePayload values.');
            }
        }

        $this->lines = array_values($lines);
        $this->note = $note === null ? null : MutationCommand::safeToken($note, 'note', 2000);
        $this->customerId = MutationCommand::nullableUuid($customerId, 'customerId');
        $this->deviceId = MutationCommand::nullableUuid($deviceId, 'deviceId');
        $this->couponId = MutationCommand::nullableUuid($couponId, 'couponId');
        $this->tableIds = MutationCommand::canonicalSet(array_map(static fn (string $id): string => MutationCommand::uuid($id, 'tableId'), array_values($tableIds)), static fn (string $id): string => $id, 'tableIds');
        if (($guestCount !== null && $guestCount < 1) || ($splitPeopleCount !== null && $splitPeopleCount < 2)) {
            throw new InvalidArgumentException('guestCount or splitPeopleCount is invalid.');
        }
        $this->locale = $locale instanceof SupportedLocale ? $locale : SupportedLocale::from($locale);
    }

    public function jsonSerialize(): array
    {
        return [
            'lines' => $this->lines, 'note' => $this->note, 'external_order_id' => $this->externalOrderId,
            'client_order_id' => $this->clientOrderId, 'order_type' => $this->orderType->value,
            'pickup_type' => $this->pickupType->value, 'scheduled_pickup_at' => $this->scheduledPickupAt,
            'contact' => $this->contact, 'customer_id' => $this->customerId, 'guest_count' => $this->guestCount,
            'table_ids' => $this->tableIds, 'locale' => $this->locale->value, 'channel' => $this->channel->value,
            'device_id' => $this->deviceId, 'coupon_id' => $this->couponId, 'coupon_code' => $this->couponCode,
            'status' => $this->status->value, 'split_mode' => $this->splitMode?->value,
            'split_people_count' => $this->splitPeopleCount,
            'pricing_evidence' => $this->pricingEvidence,
            'service_charge' => $this->serviceCharge,
        ];
    }
}
