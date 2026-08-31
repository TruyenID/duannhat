<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 (7b) — mặc định thời gian chuẩn bị **mỗi món**, do Ordering công bố.
 *
 * Đây là cạnh module rẻ nhất trong cả gói và cũng dễ bị xem thường nhất: nó là
 * MỘT hằng số. `BrandSettingsService` (Organization) phát ra
 * `effective_prep_minutes_per_item` cho màn hình HQ, và để làm được nó phải biết
 * con số mà `EffectiveOrderPolicyService` (Ordering) rơi về khi cả shop lẫn brand
 * đều bỏ trống. Trước bản vá nó `use App\Services\Shop\EffectiveOrderPolicyService`
 * chỉ để đọc một hằng số — kéo cả một service Ordering vào Organization.
 *
 * **Không chép giá trị sang Organization.** Đó là cách sửa hiển nhiên và sai:
 * hai hằng số cùng tên ở hai module lệch nhau vào ngày ai đó chỉnh một cái, và
 * triệu chứng là màn hình HQ hứa một ETA khác cái POS in ra — không có test nào
 * so hai con số ấy với nhau.
 *
 * #1160 — cố ý để nhỏ: ETA giờ là tích thuần `perItem × quantity`, nên một mặc
 * định lớn sẽ hứa thời gian chờ vô lý cho giỏ hàng nhiều món.
 */
final class OrderPrepTimeDefaults
{
    /** Số phút chuẩn bị mỗi món khi không shop lẫn brand nào khai. */
    public const MINUTES_PER_ITEM = 5;

    private function __construct() {}
}
