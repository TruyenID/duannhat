<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

use Carbon\CarbonImmutable;

/**
 * #1622 — cổng Catalog công bố: **floating section nào đang phát sóng ngay bây giờ**.
 *
 * ## Vì sao cổng này tồn tại, và vì sao nó KHÔNG phải "trả một cái đọc thô"
 *
 * Vị ngữ cửa sổ hiệu lực — khoảng ngày của section, khoảng ngày của lịch, mặt nạ
 * thứ trong tuần, và **hai nhánh qua nửa đêm** (tối nay sau giờ mở · sáng nay
 * trước giờ đóng của hôm qua) — trước #1622 được viết **ba lần**, ở ba module:
 *
 * | chỗ | module | ghi chú tại chỗ |
 * |---|---|---|
 * | `FloatingSectionPriceResolver` | Pricing | `DB::table` thô sang bảng của Catalog |
 * | `CustomerMenuService` | CustomerEngagement | comment tự khai *"same day/time logic as FloatingSectionPriceResolver, incl. overnight windows"* |
 * | `MenuCatalogReplicaBuilder` | Workstation | comment tự khai đang khớp với cái Cloud làm |
 *
 * Ba bản sao của một luật **liên quan tới tiền**. Chúng lệch nhau thì hỏng theo
 * cách tệ nhất có thể: thực đơn khách hiển thị một section đang giảm giá mà động
 * cơ đơn hàng **không** coi là đang hiệu lực ⇒ khách thấy một giá, bị tính một
 * giá khác. Không cổng nào của #962 nhìn thấy chuyện đó — deptrac chỉ đọc `use`
 * tĩnh, còn `architecture:raw-table-reads` đếm được cái đọc thô nhưng **không**
 * đếm được cái trùng lặp.
 *
 * Nên cổng này KHÔNG được dựng để hạ một con số đo. Nó dựng để luật đó chỉ còn
 * **một** bản, ở module sở hữu dữ liệu, và hai chỗ gọi kia không thể lệch nữa.
 *
 * ## Ranh giới đặt ở đâu
 *
 * Catalog trả lời *"cái gì đang phát sóng"*. Pricing trả lời *"vậy tính giá nào"*.
 * Vì thế {@see livePricesForSkus} trả về **ứng viên không xếp hạng** — xem
 * {@see FloatingSectionSkuPrice} về lý do luật "rẻ nhất thắng" ở lại Pricing.
 *
 * `MenuCatalogReplicaBuilder` cố ý **chưa** chuyển sang cổng này: nó dựng feed
 * replica cho Go với hình dạng hoàn toàn khác (nó cần cả section KHÔNG phát sóng,
 * kèm lịch, để workstation tự đánh giá offline). Bản sao thứ ba đó là việc riêng.
 */
interface FloatingSectionAvailability
{
    /**
     * Id các floating section của chi nhánh đang phát sóng tại `$at`.
     *
     * `$at` null ⇒ "bây giờ" theo đồng hồ NGHIỆP VỤ của chi nhánh
     * (`App\Support\BusinessClock`), không phải đồng hồ app — cửa sổ khuyến
     * mãi là thời gian nghiệp vụ (#1091).
     *
     * @return list<string>
     */
    public function liveSectionIds(string $branchId, ?CarbonImmutable $at = null): array;

    /**
     * Mọi dòng giá mà các section đang phát sóng chào cho `$productSkuIds`.
     *
     * **Không xếp hạng, và có thể nhiều dòng cho cùng một SKU** — hai section
     * cùng phát sóng cùng chào một món là chuyện thường. Chỗ gọi chọn.
     *
     * @param  list<string>  $productSkuIds
     * @return list<FloatingSectionSkuPrice>
     */
    public function livePricesForSkus(string $branchId, array $productSkuIds, ?CarbonImmutable $at = null): array;
}
