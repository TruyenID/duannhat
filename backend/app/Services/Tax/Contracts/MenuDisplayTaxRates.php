<?php

declare(strict_types=1);

namespace App\Services\Tax\Contracts;

/**
 * #1596 — Catalog xin Pricing tỉ lệ thuế để HIỂN THỊ trên thực đơn khách, thay
 * vì tự cầm `App\Services\Customer\TaxResolver` (Pricing).
 *
 * Docblock ở thư mục này KHÔNG được viết ra tên model đầy đủ (kể cả trong văn
 * xuôi): `DomainMutationContractsTest` quét cả file, không riêng phần `use`.
 *
 * ## Vì sao KHÔNG dùng lại `App\Services\Order\Contracts\OrderLineTaxPricing`
 *
 * Cổng kia giải thuế cho ĐƯỜNG TIỀN và cố ý phát cảnh báo "không tầng nào giải
 * ra tỉ lệ" — nghĩa là "sắp có một lần thu thiếu thuế". Endpoint thực đơn bị gọi
 * ở MỌI lượt xem trang của khách, nên phát cùng cảnh báo đó ở đây sẽ chôn vùi
 * những lần xảy ra thật dưới lưu lượng hiển thị. Sự khác biệt đó đã được cân nhắc
 * và ghi lại ở `resolveRateForDisplay`; nó là lý do tồn tại của cổng riêng này,
 * không phải trùng lặp.
 *
 * Thứ tự tầng thì KHÔNG khác: cả hai đường đi qua đúng một chuỗi tầng
 * (`walk()` trong Pricing). Một hiện thực tự xếp lại tầng ở đây là dựng động cơ
 * thuế thứ hai — màn hình sẽ quảng cáo một tỉ lệ mà hoá đơn phủ nhận, đúng lỗi
 * mà #1099 đã sửa một lần.
 *
 * ## Vì sao là "batch"
 *
 * Bộ giải thuế memo hoá mặc định chi nhánh/brand/menu/mục **theo từng instance**,
 * và một thực đơn 300 món phải tốn 1 truy vấn mỗi loại mặc định chứ không phải
 * 300. Một cổng phẳng giải qua container sẽ hoặc đẻ một bộ giải mới mỗi món (mất
 * memo — trần đếm truy vấn của endpoint thực đơn đã bắt đúng lỗi này một lần),
 * hoặc dùng chung một singleton sống suốt request (memo ôi thiu giữa hai thao
 * tác). `beginBatch()` giữ nguyên vòng đời cũ: **một lần dựng thực đơn = một
 * memo**, đúng như `$this->menuTaxResolver` trước đây.
 */
interface MenuDisplayTaxRates
{
    /**
     * Mở một lô hiển thị mới — memo mặc định chi nhánh/brand/menu/mục dùng chung
     * cho mọi món trong lô, và chết cùng lô.
     */
    public function beginBatch(): MenuDisplayTaxRateBatch;
}
