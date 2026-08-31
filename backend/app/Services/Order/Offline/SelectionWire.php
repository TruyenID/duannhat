<?php

namespace App\Services\Order\Offline;

use App\Omnify\Enums\CustomerOrderPickupTypeEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Services\DomainMutation\SupportedLocale;
use App\Services\Order\Enums\OrderChannel;
use App\Services\Order\Enums\OrderSplitMode;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderSelectionPayload;
use App\Services\Order\ValueObjects\OrderToppingSelectionPayload;

/**
 * Wire format of an offline-order selection (#1094/#1097).
 *
 * The workstation serializes the selection it SIGNED with exactly these field
 * names; Cloud reconstructs the payload from them and recomputes the digest.
 * The Go↔PHP parity gate (tests/Fixtures/offline_signing_golden.json, pinned
 * by OfflineSigningGoldenTest and the workstation twin) breaks loudly if this
 * mapping and the Go struct ever drift — change either only in lockstep.
 */
final class SelectionWire
{
    /** @param array<string, mixed> $wire */
    public static function parse(array $wire): OrderSelectionPayload
    {
        $lines = array_map(
            static fn (array $line): OrderLineSelectionPayload => new OrderLineSelectionPayload(
                $line['line_id'],
                $line['menu_product_sku_id'] ?? null,
                (int) $line['quantity'],
                array_map(
                    static fn (array $t): OrderToppingSelectionPayload => new OrderToppingSelectionPayload(
                        $t['topping_group_item_id'],
                        $t['product_sku_id'],
                        (int) $t['quantity'],
                        $t['note'] ?? null,
                    ),
                    $line['toppings'] ?? [],
                ),
                $line['note'] ?? null,
                $line['product_sku_id'] ?? null,
            ),
            $wire['lines'],
        );

        return new OrderSelectionPayload(
            lines: $lines,
            orderType: isset($wire['order_type']) ? CustomerOrderTypeEnum::from($wire['order_type']) : CustomerOrderTypeEnum::Spot,
            pickupType: isset($wire['pickup_type']) ? CustomerOrderPickupTypeEnum::from($wire['pickup_type']) : CustomerOrderPickupTypeEnum::Immediate,
            scheduledPickupAt: $wire['scheduled_pickup_at'] ?? null,
            customerId: $wire['customer_id'] ?? null,
            guestCount: $wire['guest_count'] ?? null,
            tableIds: $wire['table_ids'] ?? [],
            locale: isset($wire['locale']) ? SupportedLocale::from($wire['locale']) : SupportedLocale::Japanese,
            channel: isset($wire['channel']) ? OrderChannel::from($wire['channel']) : OrderChannel::Api,
            deviceId: $wire['device_id'] ?? null,
            couponCode: $wire['coupon_code'] ?? null,
            // #2860 — chuẩn hoá cho tầng domain, nhưng giữ chuỗi gốc cho digest
            // ngay bên dưới. Máy trạm ngoài hiện trường không tự cập nhật, nên
            // tên cũ còn tới đây; nếu ném ở đây thì một đơn offline hợp lệ bị
            // từ chối chỉ vì cách viết.
            splitMode: OrderSplitMode::fromWire($wire['split_mode'] ?? null),
            splitPeopleCount: $wire['split_people_count'] ?? null,
            note: $wire['note'] ?? null,
            splitModeWire: $wire['split_mode'] ?? null,
        );
    }
}
