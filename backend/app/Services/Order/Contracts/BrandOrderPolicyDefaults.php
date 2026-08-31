<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 — Ordering hỏi "HQ đặt mặc định gì cho luồng đơn của thương hiệu này".
 *
 * `brand_order_policies` thuộc Organization; `EffectiveOrderPolicyService` thuộc
 * Ordering (#1589 khai như vậy vì nó làm CHÍNH SÁCH ĐƠN, chỉ tình cờ nằm trong
 * `App\Services\Shop`). Nó merge mặc định của brand với override của shop, nên
 * nó buộc phải đọc bảng kia — cạnh là thật, cái sai là ĐỌC THẲNG.
 *
 * ## Vì sao cổng do ORDERING khai, không phải Organization
 *
 * Cùng lý do #1662: `App\Services\Order\Contracts` đã nằm trong
 * `published_contract_namespaces`, còn cổng đặt bên Organization thì phải thêm
 * một dòng vào `config/modules.php` — file mà nhiều phiên song song cùng sửa.
 * Consumer khai interface, provider hiện thực.
 *
 * ## MỘT method, không phải năm getter
 *
 * Bản nháp đầu tách `defaultPrintLabelLocale()` / `defaultTableStatusAfterPayment()`
 * / … Đo lại: `resolveUncached()` đọc BA khoá cùng lúc từ MỘT hàng, nên năm
 * getter biến một truy vấn thành ba. Một hàng, một câu hỏi.
 *
 * Mọi khoá đều nullable, và `null` LUÔN nghĩa là "HQ chưa chọn" — không phải
 * lỗi. Người gọi tự quyết mặc định cuối cùng, vì chuỗi shop → brand → hằng số là
 * luật của Ordering chứ không phải của bảng.
 */
interface BrandOrderPolicyDefaults
{
    /**
     * Mặc định cấp brand. Brand không tồn tại / chưa có hàng chính sách ⇒ mọi
     * khoá là `null`, KHÔNG ném.
     *
     * @return array{
     *     prep_before_payment: ?bool,
     *     confirmation_timeout_minutes: ?int,
     *     prep_minutes_per_item: ?int,
     *     print_label_locale: ?string,
     *     table_status_after_payment: ?string,
     * }
     */
    public function forBrand(?string $brandId): array;
}
