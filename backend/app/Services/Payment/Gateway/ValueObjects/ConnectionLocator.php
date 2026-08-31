<?php

namespace App\Services\Payment\Gateway\ValueObjects;

/**
 * #2938 — câu trả lời KHÔNG TRẠNG THÁI của một adapter cho câu hỏi
 * "sự kiện này thuộc connection nào?".
 *
 * ## Vì sao không trả thẳng một connection
 *
 * Con gà và quả trứng: phải biết connection TRƯỚC mới biết dùng webhook secret
 * nào để xác minh chữ ký — nên không thể hỏi một adapter instance đã gắn với
 * connection. Adapter chỉ được phép đọc payload (thứ CHƯA tin được) và mô tả
 * cách tra; việc chạm DB nằm ở `WebhookConnectionResolver`.
 *
 * ## Hai phần, hai nghĩa khác nhau
 *
 * - `lookups` — danh sách phép tra THEO THỨ TỰ. Phép đầu tiên ra kết quả thì
 *   thắng; hết danh sách mà không ra ⇒ TỪ CHỐI (không có nhánh rơi ngầm).
 * - `bindingMerchantAccountIds` — định danh merchant mà **chính sự kiện tự
 *   khai**. Nó KHÔNG phải một phép tra: nó là ràng buộc dùng để chặn gợi ý
 *   `?connection={uuid}` rehome sự kiện sang connection khác. Adapter nào mà
 *   định danh merchant KHÔNG phân biệt được tenant (một merchant account dùng
 *   chung cho cả deployment) thì để RỖNG — khai bừa vào đây sẽ biến một chuỗi
 *   không có nghĩa định danh thành một rào an ninh giả.
 *
 * Tên nhà cung cấp cố ý KHÔNG xuất hiện trong file này. Đó là biên giới
 * provider-neutral, và `GatewayDataObjectsTest` cưỡng chế bằng phép quét văn
 * bản — chính rào đó bắt được bản nháp đầu của #2938.
 */
final class ConnectionLocator
{
    /**
     * @param  list<ConnectionLookup>  $lookups
     * @param  list<string>  $bindingMerchantAccountIds
     */
    public function __construct(
        public readonly array $lookups,
        public readonly array $bindingMerchantAccountIds = [],
    ) {}
}
