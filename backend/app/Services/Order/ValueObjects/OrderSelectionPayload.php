<?php

namespace App\Services\Order\ValueObjects;

use App\Omnify\Enums\CustomerOrderPickupTypeEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Services\DomainMutation\CanonicalMutationPayload;
use App\Services\DomainMutation\ComputesCanonicalPayload;
use App\Services\DomainMutation\MutationCommand;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Order\Enums\OrderChannel;
use App\Services\Order\Enums\OrderSplitMode;
use App\Services\Order\Offline\OfflineOrderSigningMessage;
use InvalidArgumentException;

/** Caller-controlled selection intent. It deliberately contains no price, tax, total, promotion snapshot, or lifecycle status. */
final readonly class OrderSelectionPayload implements CanonicalMutationPayload
{
    use ComputesCanonicalPayload;

    /** @var non-empty-list<OrderLineSelectionPayload> */
    public array $lines;

    /** @var list<string> */
    public array $tableIds;

    public ?string $customerId;

    public ?string $deviceId;

    /**
     * #2860 — CHUỖI THIẾT BỊ ĐÃ KÝ, nguyên văn, không chuẩn hoá.
     *
     * `split_mode` nằm trong signed bytes ({@see OfflineOrderSigningMessage::selectionDigest()}).
     * Chữ ký phủ lên thứ THIẾT BỊ GỬI, nên digest phải dựng lại từ đúng chuỗi
     * đó. Chuẩn hoá trước khi verify — `equal` → `even` — làm message dựng lại
     * khác message đã ký, chữ ký fail, và **mọi đơn bán offline của máy chưa
     * cập nhật bị từ chối**.
     *
     * Hai trường, một luật rõ: `$splitModeWire` là *"thiết bị đã ký gì"*,
     * `$splitMode` là *"ta hiểu đó là gì"*. Đây không phải trôi từ vựng — trong
     * một hệ chứng cứ có chữ ký, hai câu đó vốn khác nhau.
     *
     * Đơn không đến từ thiết bị (API thường) thì hai trường luôn trùng nhau.
     */
    public ?string $splitModeWire;

    public function __construct(
        array $lines,
        public CustomerOrderTypeEnum $orderType = CustomerOrderTypeEnum::Spot,
        public CustomerOrderPickupTypeEnum $pickupType = CustomerOrderPickupTypeEnum::Immediate,
        public ?string $scheduledPickupAt = null,
        public ?OrderContactPayload $contact = null,
        ?string $customerId = null,
        public ?int $guestCount = null,
        array $tableIds = [],
        public SupportedLocale $locale = SupportedLocale::Japanese,
        public OrderChannel $channel = OrderChannel::Api,
        ?string $deviceId = null,
        public ?string $couponCode = null,
        public ?OrderSplitMode $splitMode = null,
        public ?int $splitPeopleCount = null,
        public ?string $note = null,
        ?string $splitModeWire = null,
    ) {
        if ($lines === []) {
            throw new InvalidArgumentException('An order selection requires at least one line.');
        }

        foreach ($lines as $line) {
            if (! $line instanceof OrderLineSelectionPayload) {
                throw new InvalidArgumentException('lines must contain OrderLineSelectionPayload values.');
            }
        }

        // Lines are a true ordered list; duplicate line identities are still invalid.
        $seen = [];
        foreach ($lines as $line) {
            if (isset($seen[$line->lineId])) {
                throw new InvalidArgumentException('lines cannot contain duplicate line identities.');
            }
            $seen[$line->lineId] = true;
        }
        $this->lines = array_values($lines);
        $this->customerId = MutationCommand::nullableUuid($customerId, 'customerId');
        $this->deviceId = MutationCommand::nullableUuid($deviceId, 'deviceId');
        $this->tableIds = MutationCommand::canonicalSet(
            array_map(static fn (string $id): string => MutationCommand::uuid($id, 'tableId'), array_values($tableIds)),
            static fn (string $id): string => $id,
            'tableIds',
        );

        // Mặc định về giá trị canonical: chỗ nào không đến từ một chữ ký thì
        // "thiết bị đã ký gì" và "ta hiểu đó là gì" là một.
        $this->splitModeWire = $splitModeWire ?? $splitMode?->value;

        if (($guestCount !== null && $guestCount < 1) || ($splitPeopleCount !== null && $splitPeopleCount < 2)) {
            throw new InvalidArgumentException('guestCount or splitPeopleCount is invalid.');
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'lines' => $this->lines, 'order_type' => $this->orderType->value, 'pickup_type' => $this->pickupType->value,
            'scheduled_pickup_at' => $this->scheduledPickupAt, 'contact' => $this->contact, 'customer_id' => $this->customerId,
            'guest_count' => $this->guestCount, 'table_ids' => $this->tableIds, 'locale' => $this->locale->value,
            'channel' => $this->channel->value, 'device_id' => $this->deviceId, 'coupon_code' => $this->couponCode,
            'split_mode' => $this->splitMode?->value, 'split_people_count' => $this->splitPeopleCount, 'note' => $this->note,
        ];
    }
}
