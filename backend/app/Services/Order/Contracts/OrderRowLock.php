<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1622 — Ordering công bố quyền **KHOÁ** một dòng đơn, tách khỏi quyền ĐỌC.
 *
 * {@see OrderQueryPort} chỉ đọc, nên chỗ nào cần tuần tự hoá hai request trên
 * cùng một đơn đều phải tự `DB::table('customer_orders')->lockForUpdate()` —
 * tức tự chạm bảng của Ordering. Deptrac không thấy (không import class nào);
 * chỉ `architecture:raw-table-reads` đếm được.
 *
 * ## Trả `void`, không trả dữ liệu — có chủ ý
 *
 * Chỗ gọi hiện tại (`PosInvoiceService`) **vứt bỏ** kết quả: nó đã cầm sẵn đơn,
 * và thứ nó cần là **hàng đợi**, không phải giá trị. Một chữ ký
 * `lockForUpdate(): ?OrderSnapshot` sẽ nhìn hữu ích hơn nhưng là **đoán trước**
 * nhu cầu chưa có, và nó buộc adapter đọc lại/dựng snapshot ở mọi lời gọi kể cả
 * khi không ai dùng.
 *
 * #1603 (ví QR) **cũng** cần khoá, nhưng cần cả GIÁ TRỊ sau khi khoá — đó là
 * một method khác, thiết kế khi thật sự dựng, không phải bây giờ.
 *
 * ## Bắt buộc gọi TRONG một transaction
 *
 * `SELECT … FOR UPDATE` ngoài transaction nhả khoá ngay khi câu lệnh kết thúc:
 * lệnh chạy, không lỗi, và **không khoá gì cả**. Đó là lý do method này không tự
 * mở transaction — nó không biết biên giao dịch của chỗ gọi, và mở một cái riêng
 * sẽ tạo ra đúng cái ảo giác an toàn ấy.
 */
interface OrderRowLock
{
    /**
     * Khoá dòng đơn cho tới hết transaction hiện tại.
     *
     * Id không tồn tại thì **không** báo lỗi: bản cũ cũng vậy (`->first()` trả
     * `null` và đi tiếp). Việc đơn có tồn tại hay không là câu hỏi của chỗ gọi,
     * đã trả lời trước khi tới đây.
     */
    public function lockForUpdate(string $orderId): void;
}
