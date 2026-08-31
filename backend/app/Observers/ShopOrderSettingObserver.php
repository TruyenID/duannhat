<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ShopOrderSetting;
use App\Services\Catalog\Contracts\CatalogRevisionMarker;

/**
 * #1661 — tầng 5 của chuỗi thuế (#1218): loại thuế MẶC ĐỊNH của chi nhánh.
 *
 * Nằm ở Ordering vì `shop_order_settings` là bảng của Ordering, và báo sang
 * Catalog qua cổng công bố {@see CatalogRevisionMarker}. Chiều ngược lại —
 * Catalog tự observe bảng này — là chiều deptrac chặn, và chặn đúng.
 *
 * Đánh dấu ở MỌI lượt lưu, không lọc theo `default_tax_type_id`:
 * `CatalogRevisionService::bumpFor()` đã so hash cả bản đồ giá (BR-CR02), nên
 * lưu một cài đặt không liên quan sẽ không mint bản mới. Lọc ở đây là dựng luật
 * "cái gì đáng kể" thứ hai, và luật thứ hai luôn là luật trôi lệch.
 */
final class ShopOrderSettingObserver
{
    public function __construct(private readonly CatalogRevisionMarker $revisions) {}

    public function saved(ShopOrderSetting $setting): void
    {
        $this->mark($setting);
    }

    public function deleted(ShopOrderSetting $setting): void
    {
        $this->mark($setting);
    }

    public function restored(ShopOrderSetting $setting): void
    {
        $this->mark($setting);
    }

    private function mark(ShopOrderSetting $setting): void
    {
        $this->revisions->markDirty(
            $setting->branch_id === null ? null : (string) $setting->branch_id,
        );
    }
}
