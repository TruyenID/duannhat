<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #2863 — trả `order_payments.received_by_id` về NULL ở những hàng đang mang
 * UUID toàn số 0.
 *
 * # Vì sao giá trị đó là lời nói dối, không phải dữ liệu
 *
 * Cột này là "actor" đa hình theo quy ước: khoản qua POS mang **user id**, khoản
 * qua workstation/kiosk/handy mang **device id**. Khoản customer-web tự xác nhận
 * (webhook Stripe, ví PayPay, Konbini/銀行振込 chờ nộp) không có cả hai — và
 * `schemas/Backend/Product/OrderPayment.yaml` đã khai `nullable: true` ĐÚNG vì ca
 * đó, kèm chú thích "online auto-confirm payments have no human staff".
 *
 * `OrderPaymentService` vẫn ghi đè lên đó một hằng `00000000-0000-0000-0000-
 * 000000000000`. Đo trên production ngày 2026-08-13: **145/414** khoản mang giá
 * trị này, và **không một hàng `users` nào** mang id đó. Hại thật: nó trông như
 * một UUID hợp lệ, nên ai join sang `users` được 0 hàng và kết luận "dữ liệu
 * hỏng", còn ai đọc thẳng thì kết luận "có người thu tiền". NULL nói đúng sự
 * thật — không có ai cả — và một cột trống không bao giờ bị đọc nhầm thành bằng
 * chứng.
 *
 * # Vì sao viết lại dữ liệu chứ không đọc rộng ra
 *
 * Ruling #2188: dữ liệu cũ backfill LẦN CUỐI rồi thôi, không sống chung với một
 * nhánh `=== SENTINEL ? null : …` ở mọi chỗ đọc. Ba chỗ ghi đã sửa lo phần tương
 * lai; migration này lo phần quá khứ. Cần cả hai.
 *
 * # An toàn khi chạy 14:30 thứ Bảy đông khách
 *
 * Câu hỏi bắt buộc trước khi thêm bất cứ gì vào đường deploy (`migrate --force`
 * chạy không người trông). Trả lời được, và không phụ thuộc giờ deploy:
 *
 *   - Không chạm cột tiền nào: `amount`, `status`, `paid_at`, `till_session_id`
 *     đứng yên. Mọi báo cáo doanh thu / đối soát ca đọc các cột đó.
 *   - Không có mã nào ĐỌC `received_by_id` để quyết định tiền. Chỗ duy nhất
 *     `?? throw` là `OrderPaymentOrchestrationCompat::mutationContextFromPayment`,
 *     chỉ với dòng `pending` CÓ `payment_attempt_id`; hàng sentinel không thoả:
 *     hai đường Stripe/PayPay sinh ra đã `succeeded`, đường async pending cố ý
 *     không mang attempt.
 *   - Refund/chargeback chép `received_by_id` từ hàng gốc, nên chúng cũng mang
 *     sentinel và cũng được dọn bằng chính `WHERE` này — không cần liệt kê riêng.
 *
 * # Idempotent
 *
 * `WHERE` bám đúng giá trị cũ, nên chạy lại không đổi gì thêm.
 *
 * # Không có `down()`
 *
 * Chiều ngược lại là ghi lại một giá trị đã được chứng minh là bịa. `down()` như
 * vậy tạo ra dữ liệu trông như thật mà sai — tệ hơn là không có đường lùi.
 */
return new class extends Migration
{
    /**
     * Khai lại tường minh thay vì trỏ tới một hằng trong `app/`: migration phải
     * đọc được như hồ sơ của chính lượt chuyển đổi này, kể cả sau khi hằng kia
     * bị xoá.
     */
    private const SENTINEL_ACTOR_ID = '00000000-0000-0000-0000-000000000000';

    public function up(): void
    {
        DB::table('order_payments')
            ->where('received_by_id', self::SENTINEL_ACTOR_ID)
            ->update(['received_by_id' => null]);
    }

    public function down(): void
    {
        // Cố ý rỗng — xem docblock.
    }
};
