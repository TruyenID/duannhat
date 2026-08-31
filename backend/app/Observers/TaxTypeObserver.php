<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\TaxType;
use App\Services\Catalog\Contracts\CatalogRevisionMarker;

/**
 * #1661 — tầng 6 của chuỗi thuế (#1218) **và mọi thuế suất**.
 *
 * Hai thứ khác nhau cùng sống trên `tax_types`, và cả hai đều đổi số tiền khách
 * trả ở mọi chi nhánh của thương hiệu:
 *
 *  - `is_default` — tầng cuối cùng khi năm tầng trên không phân giải được;
 *  - `rate` — **không** phải một tầng, nhưng đổi nó là đổi con số. Feed menu của
 *    workstation chở cả danh sách `tax_types` (kèm `rate`) lẫn
 *    `effective_tax_rate` đã phân giải, nên một lần sửa thuế suất mà bản catalog
 *    không tiến sẽ khiến thiết bị nhận 304 và tiếp tục in theo suất cũ.
 *
 * Nằm ở Pricing vì `tax_types` là bảng của Pricing; báo sang Catalog qua cổng
 * công bố {@see CatalogRevisionMarker}.
 *
 * Đánh dấu theo THƯƠNG HIỆU, không theo chi nhánh: một loại thuế thuộc thương
 * hiệu và có thể được bất kỳ menu nào của bất kỳ chi nhánh nào trỏ tới.
 */
final class TaxTypeObserver
{
    public function __construct(private readonly CatalogRevisionMarker $revisions) {}

    public function saved(TaxType $taxType): void
    {
        $this->mark($taxType);
    }

    public function deleted(TaxType $taxType): void
    {
        $this->mark($taxType);
    }

    public function restored(TaxType $taxType): void
    {
        $this->mark($taxType);
    }

    private function mark(TaxType $taxType): void
    {
        $this->revisions->markBrandDirty(
            $taxType->brand_id === null ? null : (string) $taxType->brand_id,
        );
    }
}
