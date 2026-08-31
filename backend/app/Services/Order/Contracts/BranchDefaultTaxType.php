<?php

namespace App\Services\Order\Contracts;

/**
 * #962 — cổng Ordering công bố cho Pricing: loại thuế MẶC ĐỊNH của một chi nhánh.
 *
 * `shop_order_settings` là bảng của Ordering, nhưng cột `default_tax_type_id`
 * trên nó là bậc thứ 5 trong chuỗi phân giải thuế của `TaxResolver`
 * (`MenuProduct → MenuMenuSection → Menu → Product → branch → brand`). Nên
 * Pricing phải hỏi được câu đó mà không cầm model của Ordering.
 *
 * **Trả về ID, không trả về `TaxType`.** `App\Models\TaxType` thuộc Pricing, và
 * `PublishedContracts` không được phụ thuộc module nào — một cổng trả về model
 * đó sẽ đỏ ngay tại rào. Trả id còn đúng về mặt sở hữu: Ordering biết chi nhánh
 * TRỎ tới loại thuế nào, Pricing mới là bên biết loại thuế đó nghĩa là gì.
 */
interface BranchDefaultTaxType
{
    /**
     * Id loại thuế mặc định của chi nhánh, hoặc null khi chi nhánh chưa có
     * `shop_order_settings` hoặc để trống cột đó.
     */
    public function defaultTaxTypeIdForBranch(string $branchId): ?string;
}
