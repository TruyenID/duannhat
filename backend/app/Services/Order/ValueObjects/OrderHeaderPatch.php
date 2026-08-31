<?php

namespace App\Services\Order\ValueObjects;

use App\Omnify\Enums\CustomerOrderPickupTypeEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;

final readonly class OrderHeaderPatch implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    public ?string $customerId;

    public function __construct(
        public ?CustomerOrderPickupTypeEnum $pickupType = null,
        public ?string $scheduledPickupAt = null,
        public ?OrderContactPayload $contact = null,
        ?string $customerId = null,
        public ?int $guestCount = null,
        public ?string $note = null,
        // An order_type flip re-resolves every line's tax rate (plan-043 §7:
        // takeaway <-> dine-in moves a bento line between 8% and 10%), so the
        // patch must be able to carry it or the Shop update transport cannot
        // ride the facade without dropping the field (#1090).
        public ?CustomerOrderTypeEnum $orderType = null,
    ) {
        $this->customerId = MutationCommand::nullableUuid($customerId, 'customerId');
        if ($guestCount !== null && $guestCount < 1) {
            throw new \InvalidArgumentException('guestCount must be positive when supplied.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['pickup_type' => $this->pickupType?->value, 'scheduled_pickup_at' => $this->scheduledPickupAt, 'contact' => $this->contact, 'customer_id' => $this->customerId, 'guest_count' => $this->guestCount, 'note' => $this->note, 'order_type' => $this->orderType?->value];
    }
}
