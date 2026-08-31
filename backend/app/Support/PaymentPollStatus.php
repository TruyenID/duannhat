<?php

namespace App\Support;

use App\Omnify\Enums\PaymentStatusEnum;

/**
 * Hợp đồng CHUỖI TRẠNG THÁI mà kiosk và workstation nhận khi poll một payment.
 *
 * Tách ra từ `PaymentStatusCompatibility` khi class đó bị xoá (#1822). Class cũ
 * trộn hai thứ khác hẳn nhau dưới một cái tên:
 *
 *  - **lớp tương thích legacy** — ánh xạ chuỗi `'confirmed'` của thời trước
 *    chuẩn hoá về `succeeded`, cộng `succeededLedgerStatuses()` trả về CẢ HAI để
 *    truy vấn sổ cái không sót hàng cũ. Phần này đã chết: chủ repo xác nhận
 *    2026-08-05 rằng chưa có bản phát hành nào, nên không tồn tại hàng
 *    `confirmed` nào để đọc;
 *  - **hợp đồng poll** — hai bảng ánh xạ dưới đây, vẫn đang sống và sẽ sống
 *    tiếp. Chúng KHÔNG phải tương thích ngược với cái gì cả.
 *
 * Trộn hai thứ đó dưới cái tên "Compatibility" là lý do việc xoá bị treo lâu:
 * mỗi lần ai đó định xoá lại thấy có ba controller đang gọi, và dừng lại.
 *
 * Vì sao không đặt trên chính `PaymentStatusEnum`: enum đó do Omnify sinh
 * (`DO NOT EDIT`), nên mọi hành vi thêm vào sẽ bị regen xoá.
 */
final class PaymentPollStatus
{
    /**
     * Kiosk poll: `paid | pending | failed`.
     *
     * Cố ý KHÔNG dùng giá trị enum — kiosk là màn hình cho khách, ba trạng thái
     * là ba thứ khách hiểu được. `refunded` gộp vào `failed` vì với người đang
     * đứng trước máy thì tiền không vào là không vào.
     */
    public static function forKioskPoll(PaymentStatusEnum|string|null $status): string
    {
        return match (self::canonical($status)) {
            PaymentStatusEnum::Succeeded => 'paid',
            PaymentStatusEnum::Failed, PaymentStatusEnum::Refunded => 'failed',
            default => 'pending',
        };
    }

    /**
     * Workstation poll: giá trị enum chuẩn (`succeeded` / `pending` / `failed` /
     * `refunded`).
     *
     * Workstation là phần mềm, không phải người, nên nó nhận đúng từ vựng của
     * hệ thống. Mọi trạng thái không nằm trong bốn cái trên rơi về `pending` —
     * fail-safe: thà máy hỏi lại còn hơn tưởng tiền đã vào.
     */
    public static function forWorkstationPoll(PaymentStatusEnum|string|null $status): string
    {
        return match (self::canonical($status)) {
            PaymentStatusEnum::Succeeded => PaymentStatusEnum::Succeeded->value,
            PaymentStatusEnum::Failed => PaymentStatusEnum::Failed->value,
            PaymentStatusEnum::Refunded => PaymentStatusEnum::Refunded->value,
            default => PaymentStatusEnum::Pending->value,
        };
    }

    private static function canonical(PaymentStatusEnum|string|null $status): ?PaymentStatusEnum
    {
        if ($status instanceof PaymentStatusEnum) {
            return $status;
        }

        return $status === null ? null : PaymentStatusEnum::tryFrom($status);
    }
}
