<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure;

use App\Services\Tax\Contracts\MenuDisplayTaxRateBatch;
use App\Services\Tax\Contracts\MenuDisplayTaxRates;

/**
 * #1596 — Pricing hiện thực cổng tỉ lệ thuế HIỂN THỊ mà Catalog tiêu thụ.
 *
 * ## Vì sao file này nằm ở `App\Modules\Pricing\`, không cạnh `TaxResolver`
 *
 * `TaxResolver` sống ở `App\Services\Customer\` và được `deptrac.yaml` **nêu
 * đích danh** là Pricing — cả namespace `App\Services\Customer\` còn lại thuộc
 * CustomerEngagement. Một file mới đặt cạnh nó sẽ rơi vào **CustomerEngagement**,
 * và cạnh vừa gỡ mọc lại ngay dưới tên khác. Cùng lý do đã ghi ở
 * {@see TaxResolverLineTaxPricing}.
 *
 * Mỏng có chủ ý — mọi quyết định thuế nằm trong `TaxResolver`, adapter không tự
 * đi tìm loại thuế và không tự chọn tầng.
 */
final class TaxResolverMenuDisplayRates implements MenuDisplayTaxRates
{
    /**
     * Mỗi lô một `TaxResolver` mới — đúng vòng đời memo mà docblock của
     * `TaxResolver` yêu cầu ("a fresh resolver per order operation so the memo
     * can't go stale mid-request"). Adapter này **phải** stateless / không
     * singleton-hoá bộ giải, nếu không memo sẽ sống xuyên request.
     */
    public function beginBatch(): MenuDisplayTaxRateBatch
    {
        return new TaxResolverMenuDisplayRateBatch;
    }
}
