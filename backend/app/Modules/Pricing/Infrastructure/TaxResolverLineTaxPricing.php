<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Infrastructure;

use App\Services\Order\Contracts\OrderLineTaxBatch;
use App\Services\Order\Contracts\OrderLineTaxPricing;

/**
 * #962 · 7a-7 — Pricing hiện thực cổng đóng thuế dòng đơn mà Ordering khai.
 *
 * ## Vì sao file này nằm ở `App\Modules\Pricing\`, không cạnh `TaxResolver`
 *
 * `TaxResolver` sống ở `App\Services\Customer\` và được `deptrac.yaml` **nêu đích
 * danh** là Pricing — cả namespace `App\Services\Customer\` còn lại thuộc
 * CustomerEngagement. Nên một file mới đặt cạnh nó sẽ rơi vào **CustomerEngagement**,
 * và cạnh vừa gỡ mọc lại ngay dưới tên khác. (Tiền lệ #1662 đã cắn đúng bẫy này với
 * `App\Services\Pos\`.)
 *
 * `App\Modules\Pricing\` là layer Pricing theo bản đồ, và là hình dạng đích của
 * epic #962 (Notifications đã dọn vào `App\Modules\Notifications\` ở Phase 2). Ai
 * định "dọn cho gọn" bằng cách chuyển sang `App\Services\Customer\Internal\`: đừng.
 *
 * Mỏng có chủ ý — mọi quyết định thuế nằm trong `TaxResolver`, adapter không tự đi
 * tìm `TaxType` hay tự chọn tầng.
 */
final class TaxResolverLineTaxPricing implements OrderLineTaxPricing
{
    /**
     * Mỗi lô một `TaxResolver` mới — đúng vòng đời memo mà docblock của
     * `TaxResolver` yêu cầu ("a fresh resolver per order operation so the memo can't
     * go stale mid-request"). Adapter này **phải** là stateless / không singleton-
     * hoá resolver, nếu không memo sẽ sống xuyên request.
     */
    public function beginBatch(): OrderLineTaxBatch
    {
        return new TaxResolverLineTaxBatch;
    }
}
