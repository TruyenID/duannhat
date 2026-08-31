<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1910) — phiếu レジ開け (mở ca).
 *
 * Bảy trường, đo từ `ShiftOpenReportInfo` bên workstation. Phiếu này in lúc thu
 * ngân đếm quỹ đầu ca, nên nó KHÔNG có doanh thu — mọi con số ở đây là tiền
 * mặt đang có trong két trước khi bán đồng nào.
 *
 * `openedAt` là CHUỖI đã định dạng theo timezone chi nhánh, không phải
 * timestamp: giờ nghiệp vụ được giải ở tầng gọi (#1091), tầng in chỉ đặt chữ
 * lên giấy.
 *
 * @see ShiftReportInfo phiếu 精算 (đóng ca) — cùng họ, khác vòng đời
 */
final class ShiftOpenReportInfo
{
    /** @param list<ShiftDenominationLine> $denominations */
    public function __construct(
        public readonly string $deviceName = '',
        public readonly string $operator = '',
        public readonly string $openedAt = '',
        public readonly array $denominations = [],
        /** Quỹ đầu ca đã đếm và ký. */
        public readonly int $openingFloat = 0,
        public readonly string $note = '',
        public readonly string $currency = '',
    ) {}
}
