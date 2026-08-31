<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

use App\Omnify\Enums\MenuPromotionStackingModeEnum;

/**
 * #962 — KHUYẾN MÃI ĐANG CHẠY của một món, dạng Pricing công bố cho màn hình
 * thực đơn của khách.
 *
 * Anh em với {@see ActiveMenuPromotion} (#1597) nhưng KHÔNG gộp được với nó,
 * và sự khác nhau là có thật chứ không phải trùng lặp:
 *
 * | | `ActiveMenuPromotion` | `MenuDisplayPromotion` |
 * |---|---|---|
 * | ai đọc | Ordering, để TÍNH TIỀN | customer-web, để HIỂN THỊ |
 * | `name` | không cần | cần (nhãn trên thẻ món) |
 * | `endsAt` | không cần | cần (đồng hồ đếm ngược) |
 *
 * Nhồi `name`/`endsAt` vào `ActiveMenuPromotion` là bắt đường tính tiền tính
 * thêm một mốc thời gian nó không dùng — và `endsAt` KHÔNG rẻ: nó phải phân giải
 * múi giờ chi nhánh.
 *
 * `MenuPromotionStackingModeEnum` nằm trong `App\Omnify\Enums`, vốn đã `shared`,
 * nên mang nó ở đây không làm mất tư cách công bố. Nó cũng là lý do `stackingMode`
 * giữ nguyên kiểu enum: payload cũ đẩy thẳng enum ra JSON (backed enum tự
 * serialize thành `value`), ép sang chuỗi ở đây là đổi dữ liệu client đang đọc.
 */
final readonly class MenuDisplayPromotion
{
    public function __construct(
        public string $id,
        public ?string $name,
        public float $discountPercent,
        public ?MenuPromotionStackingModeEnum $stackingMode,
        /**
         * Mốc kết thúc SỚM NHẤT sắp tới, ISO 8601 theo múi giờ chi nhánh:
         * hết cửa sổ trong ngày, hoặc `valid_until`, tuỳ cái nào đến trước.
         */
        public string $endsAt,
    ) {}
}
