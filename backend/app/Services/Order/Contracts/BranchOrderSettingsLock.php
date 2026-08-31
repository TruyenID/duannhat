<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 — Ordering công bố quyền KHOÁ-RỒI-ĐỌC `shop_order_settings` của một chi nhánh.
 *
 * ## Vì sao KHÔNG dùng {@see BranchCurrency} có sẵn
 *
 * `BranchCurrency::codeFor()` trả đúng một cột và **không khoá gì**. Chỗ gọi ở
 * `TillSessionService::open()` cần cả hai thứ mà cổng đó cố ý không có:
 *
 *  1. **hai cột** — `currency_code` *và* `prices_include_tax`, chụp lại trong CÙNG
 *     một lần đọc để ca két tự chứa (plan-043). Hai lần đọc rời là hai ảnh chụp ở
 *     hai thời điểm, tức đúng cái ca đua đang muốn chặn, chỉ nhỏ hơn.
 *  2. **`lockForUpdate()`** — AUDIT FIX 3.6 (2026-07-14). Việc mở ca đua với
 *     `PATCH /shops/{slug}/settings/order { currency_code }` (plan-031), vốn khoá
 *     CHÍNH dòng này trong transaction guard của nó. Bỏ khoá đi thì một lần lật
 *     tiền tệ / chế độ thuế lọt vào giữa lúc guard kiểm "có ca nào đang mở không"
 *     và lúc nó ghi.
 *
 * Đây là lý do cổng này tồn tại RIÊNG thay vì thêm một method vào `BranchCurrency`:
 * ở đó khoá sẽ là tuỳ chọn, và một cổng khoá tuỳ chọn là một cổng không khoá.
 *
 * ## Khoá là Ý ĐỊNH của chỗ gọi, không phải chi tiết của adapter
 *
 * Tên method nói ra chuyện khoá vì đó là thứ duy nhất phân biệt nó với một lần
 * đọc thường. Một cổng tên `settingsFor()` mà bên trong lặng lẽ khoá sẽ mất cái
 * khoá ngay lần đầu có người "dọn dẹp" adapter — và **không test nào đỏ**: câu
 * lệnh vẫn chạy, không lỗi, chỉ là không khoá gì cả.
 *
 * ## Bắt buộc gọi TRONG một transaction
 *
 * Giống {@see OrderRowLock}: `SELECT … FOR UPDATE` ngoài transaction nhả khoá ngay
 * khi câu lệnh kết thúc. Method này KHÔNG tự mở transaction — nó không biết biên
 * giao dịch của chỗ gọi, và mở một cái riêng chỉ tạo ra ảo giác an toàn.
 * `TillSessionService::open()` đã chạy trong `DB::transaction()`, và bài
 * `TillSessionOrderingPortsTest` ghim đúng điều đó.
 */
interface BranchOrderSettingsLock
{
    /**
     * Khoá dòng `shop_order_settings` của chi nhánh cho tới hết transaction hiện
     * tại, rồi trả về hai trường mà ca két chụp lúc mở.
     *
     * Chi nhánh chưa có dòng cấu hình (chi nhánh cũ, có trước khi bảng ra đời)
     * trả `null` — KHÔNG ném lỗi, đúng như `->first()` của bản cũ. Khi đó không
     * có gì để khoá, và chỗ gọi rơi về mặc định của két.
     */
    public function lockAndReadForBranch(string $branchId): ?LockedBranchOrderSettings;
}
