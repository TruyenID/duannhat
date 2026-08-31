<?php

namespace App\Services\Payment\Observation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * #2902 — ba ngưỡng quyết định "đã tới lúc rút khoá tiền ra khỏi JSON chưa".
 *
 * Tầng tiền lọc bằng JSON-path trên cột `metadata` **không index**
 * (`settles_payment_id`, `dispute_kind`, `stripe_refund_id`, `stripe_reader_id`).
 * Ở 414 hàng thì đó là 2 ms và một lượt quét toàn bảng — chưa đau. #2902 cố ý
 * KHÔNG sửa, vì phẫu thuật schema trên bảng tiền đang chạy để chữa một thứ tốn
 * 2 ms là đúng kiểu thay đổi gây sự cố.
 *
 * Cái issue để lại là **ba ngưỡng**. Lớp này biến chúng thành một phép đo chạy
 * được, để lần sau không ai phải dựng lại toàn bộ phân tích — đó chính là mục
 * đích issue tự khai, và một ngưỡng không đo được thì không khác gì không có.
 *
 * KHÔNG tự sửa gì. Chỉ đọc, và trả lời đúng một câu: đã tới lúc chưa.
 */
final class JsonKeyDebtThresholds
{
    /** Số hàng `order_payments` mà quá đó thì quét toàn bảng bắt đầu đáng lo. */
    public const ROW_THRESHOLD = 20_000;

    /** Số chi nhánh ĐANG BÁN mà quá đó thì nhịp tăng hàng đổi bậc. */
    public const BRANCH_THRESHOLD = 4;

    /** Cửa sổ mặc định để định nghĩa "đang bán". */
    public const DEFAULT_WINDOW_DAYS = 30;

    /**
     * @return array{generated_at: string, window_days: int, gates: list<array{key: string, condition_met: bool, measurement: string, why: string}>, actionable: bool}
     */
    public function report(?int $windowDays = null): array
    {
        $windowDays = max(1, $windowDays ?? self::DEFAULT_WINDOW_DAYS);
        $since = Carbon::now()->subDays($windowDays);

        $rows = (int) DB::table('order_payments')->whereNull('deleted_at')->count();

        // "Đang bán" = có ít nhất một lượt thu trong cửa sổ. Định nghĩa này đo
        // được và không phụ thuộc một cột trạng thái nào có thể trôi; issue nói
        // "chi nhánh đang bán", không phải "chi nhánh tồn tại".
        $branches = (int) DB::table('order_payments')
            ->whereNull('deleted_at')
            ->whereNotNull('branch_id')
            ->where('paid_at', '>=', $since)
            ->distinct()
            ->count('branch_id');

        // Lần hoàn tiền Stripe THẬT: `re_…` chỉ sống trong metadata. Bốn hàng
        // `refunded` trên production tại thời điểm #2902 đều là backfill tay và
        // KHÔNG mang khoá này — nên đếm khoá, đừng đếm trạng thái.
        $refunds = (int) DB::table('order_payments')
            ->whereNull('deleted_at')
            ->whereNotNull('metadata->stripe_refund_id')
            ->count();

        $gates = [
            [
                'key' => 'order_payments_rows',
                'condition_met' => $rows > self::ROW_THRESHOLD,
                'measurement' => sprintf('%d hàng (ngưỡng %d)', $rows, self::ROW_THRESHOLD),
                'why' => 'mỗi lượt void/xoá/báo cáo là một lượt quét tuyến tính bảng này',
            ],
            [
                'key' => 'branches_selling',
                'condition_met' => $branches > self::BRANCH_THRESHOLD,
                'measurement' => sprintf('%d chi nhánh có thu trong %d ngày (ngưỡng %d)', $branches, $windowDays, self::BRANCH_THRESHOLD),
                'why' => 'nhịp tăng hàng tỉ lệ với số quán đang bán',
            ],
            [
                'key' => 'stripe_refund_seen',
                'condition_met' => $refunds > 0,
                'measurement' => sprintf('%d hàng mang metadata->stripe_refund_id', $refunds),
                'why' => 'đường hoàn tiền chưa từng chạy production; lần đầu không nên là tiền thật của khách',
            ],
        ];

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'window_days' => $windowDays,
            'gates' => $gates,
            'actionable' => (bool) array_filter($gates, static fn (array $g): bool => $g['condition_met']),
        ];
    }
}
