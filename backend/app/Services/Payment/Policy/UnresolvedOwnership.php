<?php

namespace App\Services\Payment\Policy;

/**
 * #3084 — dấu "quyền sở hữu CHƯA phân giải" cho connection dựng lúc chạy.
 *
 * ## Vấn đề nó thay thế
 *
 * Hai bootstrap customer-web từng ghi `Str::uuid()` vào
 * `brand_owner_org_unit_id` / `operator_org_unit_id`. Schema khai hai cột đó là
 * *"canonical legal operator/merchant owner returned by Identity"* — tức câu trả
 * lời cho **tiền này thuộc về ai về mặt pháp lý**. Một UUID ngẫu nhiên ở đó
 * không phải ô trống: nó là một câu trả lời sai, và trông y hệt dữ liệu thật.
 *
 * Đo trên production: 4 connection, 4 giá trị `operator_org_unit_id` khác nhau,
 * không giá trị nào tra ngược được về đâu.
 *
 * ## Vì sao là SENTINEL chứ không phải giá trị suy diễn
 *
 * {@see UnavailableBranchManagementProjectionSource} chốt rõ: *"Tempo không được
 * tự suy ra từ dữ liệu cục bộ, kể cả khi hai cột trên đã mirror sẵn — đây là
 * quyết định tiền thuộc về ai, và một bản mirror trễ nhịp thì âm thầm sai."*
 *
 * Sentinel tôn trọng điều đó vì nó **không khẳng định gì cả**. Nó nói đúng một
 * câu: chưa ai phân giải quyền sở hữu cho hàng này. Suy diễn từ
 * `console_organization_id` sẽ nói một câu mạnh hơn nhiều, và là câu Tempo không
 * có thẩm quyền nói.
 *
 * ## Vì sao TẤT ĐỊNH mới là phần quan trọng
 *
 * Ngẫu nhiên hỏng theo ba đường, sentinel chữa cả ba:
 *
 *  1. **Không tìm lại được.** `WHERE operator_org_unit_id = <sentinel>` liệt kê
 *     đúng những hàng cần phân giải lại vào ngày Platform công bố endpoint. Với
 *     UUID ngẫu nhiên thì không có câu truy vấn nào làm được việc đó.
 *  2. **Không mang được khoá.** Mỗi hàng một giá trị mới nghĩa là đưa cột vào
 *     UNIQUE cũng vô ích — đúng thứ chặn #3074.
 *  3. **Hỏng IM LẶNG vào một ngày không ai chọn.** `matchesOwnership()` so giá
 *     trị đã lưu với bản chiếu Identity; ngày nguồn thật lên, mọi hàng mang UUID
 *     bịa sẽ trượt và đọc ra thành "chi nhánh này không có phương thức thanh
 *     toán nào". Sentinel không sửa được việc trượt, nhưng biến nó thành thứ tra
 *     ra được bằng một câu SQL.
 *
 * Cùng khuôn với hàng connection tổng hợp của đường webhook Stripe
 * (`app/Services/Payment/ProviderEvent/`): nó cũng dùng hằng số tất định thay vì
 * UUID ngẫu nhiên, cố ý, vì cùng lý do.
 *
 * Cố ý KHÔNG `use` class đó ở đây: rào `/legacy/i` của #2188 bóc comment rồi mới
 * quét, nên nhắc tên trong lời giải thích thì không sao, còn một dòng `import`
 * là thêm một định danh mới vào `backend/app/` — và allowlist của rào đó chỉ ghi
 * nợ cũ, chỉ được teo.
 */
final class UnresolvedOwnership
{
    /**
     * Cố ý nằm ngoài dải mà hàng tổng hợp kia đã dùng (…0001 → …0005) để hai
     * khái niệm không lẫn vào nhau khi ai đó grep.
     */
    public const BRAND_OWNER_ORG_UNIT_ID = '00000000-0000-4000-8000-00000000000a';

    public const OPERATOR_ORG_UNIT_ID = '00000000-0000-4000-8000-00000000000b';

    /**
     * Hàng này mang quyền sở hữu chưa phân giải?
     *
     * Chỉ cần MỘT trong hai cột là sentinel: hai cột luôn được ghi cùng lúc, nên
     * một hàng chỉ khớp nửa là hàng đã bị sửa tay — vẫn phải phân giải lại.
     */
    public static function marks(?string $brandOwnerOrgUnitId, ?string $operatorOrgUnitId): bool
    {
        return $brandOwnerOrgUnitId === self::BRAND_OWNER_ORG_UNIT_ID
            || $operatorOrgUnitId === self::OPERATOR_ORG_UNIT_ID;
    }
}
