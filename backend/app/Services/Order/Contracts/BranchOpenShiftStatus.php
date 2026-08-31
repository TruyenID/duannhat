<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1687 (#962) — Ordering hỏi Payments: "chi nhánh này có ca thu ngân đang mở
 * / có chuỗi ca đang mở không?".
 *
 * Bốn guard giữa-ca của `PATCH /shops/{slug}/settings/order` (plan-031 tiền
 * tệ · plan-043 総額表示 · plan-045 端数処理 · #1129 cảnh báo phí phục vụ) chặn
 * 409 dựa trên đúng hai vị từ này. Khi thân transaction rời tầng HTTP
 * (Composition — được phép biết mọi module) xuống `ShopOrderSettingsService`
 * (Ordering, vì nó GHI `shop_order_settings`), hai câu hỏi đó trở thành cạnh
 * Ordering → Payments. Đảo chiều khai báo — consumer khai interface, Payments
 * hiện thực — thì cạnh biến mất mà không ai phải đổi hành vi.
 *
 * Cùng lý do và cùng khuôn với {@see OpenTillSessionLookup} (#1662): Ordering
 * publish theo NAMESPACE (`published_contract_namespaces` chứa
 * `App\Services\Order\Contracts`), còn Payments publish theo từng class.
 *
 * **Hai method vì đây là hai câu hỏi khác nhau, không phải một câu hỏi hai
 * biến thể.** Guard nào cũng gọi cả hai và OR lại, nhưng gộp sẵn thành một
 * `isBlocked()` sẽ chôn phép OR vào adapter — và ngày ai đó cần biết CÁI NÀO
 * đang chặn (thông điệp cho thu ngân) thì không còn hỏi được nữa.
 */
interface BranchOpenShiftStatus
{
    /**
     * plan-043/031 — chi nhánh có ca đang THỰC SỰ chiếm một quầy không? Khớp
     * đúng con số "N/M quầy đang mở" của dashboard két: quầy còn trỏ tới ca
     * (`current_session_id`) VÀ ca đó đang active (open/closing).
     *
     * Cả hai vế đều quan trọng:
     *   - lọc theo status → con trỏ cũ còn sót trỏ vào ca settled/abandoned/
     *     expired (lỗi multi-till, đã sửa trong TillSessionService) KHÔNG chặn.
     *   - join "quầy có trỏ tới nó" → một ca open/closing MỒ CÔI mà không quầy
     *     nào trỏ tới (bản ghi kẹt chờ reaper plan-032) cũng KHÔNG chặn. Nếu
     *     không, người vận hành thấy "0 quầy đang mở" mà vẫn ăn 409.
     */
    public function branchHasOpenShift(string $branchId): bool;

    /**
     * Plan-046 R8 (C1) — chuỗi ca (chain) của chi nhánh còn mở không: ca cuối
     * kết thúc bằng BÀN GIAO (handover) chứ không phải chốt cuối. #1130 đưa vị
     * từ này vào `TillSessionService` để endpoint pre-flight của admin và guard
     * dùng CHUNG một định nghĩa — UI và guard không thể lệch nhau.
     */
    public function branchHasOpenChain(string $branchId): bool;
}
