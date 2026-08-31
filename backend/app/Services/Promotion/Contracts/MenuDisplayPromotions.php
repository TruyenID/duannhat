<?php

declare(strict_types=1);

namespace App\Services\Promotion\Contracts;

/**
 * #962 — cổng Pricing công bố cho màn hình thực đơn: **món nào đang có khuyến
 * mãi, và nó tắt lúc nào**.
 *
 * `CustomerMenuService` (CustomerEngagement) gọi thẳng `MenuPromotionService` và
 * nhận về `array<string, App\Models\MenuPromotion|null>` — hai cạnh sang Pricing
 * cho một lời gọi. Chiều gọi đúng (thực đơn TIÊU THỤ kết quả tính khuyến mãi),
 * nhưng cái băng qua ranh giới là model.
 *
 * ## Vì sao `endsAt` được tính Ở ĐÂY chứ không phải phía gọi
 *
 * Bản cũ để `CustomerMenuService::resolvePromotionEndsAt()` tự đọc `valid_until`,
 * `daily_time_from`, `daily_time_to` của model rồi tự ghép cửa sổ — tức phía
 * KHÁCH đang giữ luật "cửa sổ khuyến mãi kết thúc lúc nào", kể cả ca vắt qua
 * nửa đêm (from 21:00, to 02:00). Ba cột đó là của Pricing; luật đọc chúng cũng
 * vậy. Trả thêm ba cột thô ra ngoài chỉ để phía kia ghép lại là giữ nguyên bản
 * sao thứ hai của luật.
 *
 * ## Vì sao một lời gọi theo LÔ
 *
 * Một thực đơn 300 món gọi 300 lần là 300 lượt phân giải phạm vi. Bản cũ đã gọi
 * theo lô (`resolveActivePromotionsForMenu`) và cổng giữ nguyên hình dạng đó —
 * đây là PR dời ranh giới, không phải PR đổi số truy vấn.
 */
interface MenuDisplayPromotions
{
    /**
     * Khuyến mãi đang chạy cho từng món, khoá theo `product_id`.
     *
     * `$branchId` dùng cho CẢ hai việc: lọc khuyến mãi theo chi nhánh, và phân
     * giải múi giờ để tính `endsAt`. Chuỗi rỗng (thực đơn cấp thương hiệu, không
     * ghim chi nhánh) là hợp lệ và rơi về múi giờ mặc định — đúng như bản cũ.
     *
     * Mỗi món trong `$items` LUÔN có mặt trong mảng trả về, giá trị `null` khi
     * không có khuyến mãi nào khớp.
     *
     * @param  list<array{product_id: string, category_ids?: list<string>}>  $items
     * @return array<string, MenuDisplayPromotion|null>
     */
    public function forMenuItems(string $branchId, array $items): array;
}
