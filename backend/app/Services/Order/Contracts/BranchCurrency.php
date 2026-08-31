<?php

namespace App\Services\Order\Contracts;

/**
 * #1589 — mã tiền tệ hiệu lực của một chi nhánh, do Ordering công bố.
 *
 * `ShopOrderSetting` về Ordering ở #1592 (đo: 2/3 người đọc là Ordering, và model
 * tên là `ShopOrderSetting`). Còn lại 16 cạnh từ ngoài, và đo từng call site theo
 * TRƯỜNG thực sự đọc thì **sáu** trong số đó chỉ đọc đúng một cột:
 *
 *     ShopOrderSetting::where('branch_id', $x)->value('currency_code')
 *
 * Sáu chỗ ấy nằm ở sáu class thuộc **bốn** module khác nhau (Payments ×3,
 * Pricing, CustomerEngagement, và một trong Ordering). Đó là lý do cổng này hẹp
 * đến mức chỉ có một method: nó không phải facade cho `ShopOrderSetting`, nó là
 * câu hỏi mà bốn module kia thật sự hỏi.
 *
 * **Trả `?string`, KHÔNG tự áp mặc định.** Sáu chỗ gọi có sáu fallback khác nhau
 * và khác nhau CÓ LÝ DO: Stripe rơi về `config('services.stripe.currency')`, két
 * rơi về `till.default_currency_code`, chỗ khác rơi về `'JPY'`. Gộp chúng vào một
 * mặc định trong cổng là đổi hành vi tiền tệ ở năm chỗ để cho code trông gọn.
 */
interface BranchCurrency
{
    /**
     * Mã tiền tệ đã cấu hình cho chi nhánh, hoặc `null` khi chưa cấu hình.
     *
     * KHÔNG chuẩn hoá hoa/thường — người gọi tự `strtoupper()` như trước, vì hai
     * trong sáu chỗ so chuỗi này với khoá cấu hình chữ thường.
     */
    public function codeFor(string $branchId): ?string;
}
