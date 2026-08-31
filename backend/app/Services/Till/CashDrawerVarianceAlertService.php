<?php

declare(strict_types=1);

namespace App\Services\Till;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\TillSession;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use Illuminate\Support\Facades\Log;

/**
 * #2937 — đưa phán đoán đối soát tiền mặt tới NGƯỜI.
 *
 * `CashDrawerReconciliationService` (#2879) tính bốn ô phán đoán nhưng không ai
 * gọi nó. Ba bảng quan sát vì thế là kho lưu trữ: đúng, đầy đủ, và không ai đọc.
 *
 * ## Chỉ BA verdict được kêu
 *
 * `ok` không kêu — hiển nhiên.
 *
 * **`undetermined` cũng KHÔNG kêu**, và đây là quyết định quan trọng hơn. Máy
 * mất kết nối lúc chốt ca, hoặc máy tự khai 在高不確定, là chuyện thường ngày.
 * Kêu mỗi ca như vậy sẽ dạy người ta tắt cảnh báo — và một rào tiền bị tắt thì
 * không còn canh gì. Nó đi vào log, ở mức `info`, để đếm được mà không đánh thức
 * ai.
 *
 * Cùng lý lẽ với `SettlementAlertService`: vế "biết IM" là vế giữ cho vế "biết
 * kêu" còn được tin.
 *
 * ## Khoá chống lặp: MỘT CA MỘT LẦN, vĩnh viễn
 *
 * `SettlementAlertService` gắn ngày nghiệp vụ vào khoá vì tình trạng của nó
 * **kéo dài** — tiền treo ở cổng hôm nay vẫn treo ngày mai, và nhắc mỗi ngày
 * một lần là đúng.
 *
 * Ở đây ngược lại: một ca đã chốt là **sự kiện bất biến**. Lệch của nó không
 * lớn thêm. Nhắc lại mỗi ngày là nhiễu thuần tuý, nên khoá chỉ mang
 * `till_session_id`.
 *
 * ## Cảnh báo hỏng KHÔNG được làm hỏng lượt quét
 *
 * Audience rỗng (chưa ai giữ role đó) cũng rơi vào `catch` này. Đối soát là thứ
 * ĐỌC dữ liệu; cảnh báo chỉ là thông báo về nó.
 */
final class CashDrawerVarianceAlertService
{
    /**
     * Ba verdict CÓ HÀNH ĐỘNG. `ok` và `undetermined` cố ý vắng mặt.
     */
    private const ALERTING_VERDICTS = [
        'human_count_error',
        'cash_left_machine_off_book',
        'cash_missing',
    ];

    public function __construct(
        private readonly CashDrawerReconciliationService $reconciler,
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    /**
     * @return array{verdict: string, alerted: bool, reason: ?string}
     */
    public function evaluate(TillSession $session): array
    {
        $outcome = $this->reconciler->reconcile($session);
        $verdict = (string) $outcome['verdict'];

        if (! in_array($verdict, self::ALERTING_VERDICTS, true)) {
            // Không kêu, nhưng vẫn đếm được. `undetermined` xảy ra hàng ngày và
            // giá trị của nó là XU HƯỚNG (máy nào hay im), không phải một lượt.
            Log::info('cash-drawer.reconciled-quiet', [
                'till_session_id' => (string) $session->id,
                'verdict' => $verdict,
                'reason' => $outcome['reason'],
                'excluded_denominations' => $outcome['excluded_denominations'],
            ]);

            return ['verdict' => $verdict, 'alerted' => false, 'reason' => 'not-alerting-verdict'];
        }

        $brand = $this->brandFor($session);

        if ($brand === null) {
            // `toRole` giải audience trong phạm vi MỘT brand — thiếu brand là
            // không gửi được. Ghi lại thay vì nuốt (bài học #2847 / #2460).
            Log::warning('cash-drawer.no-brand-for-session', [
                'till_session_id' => (string) $session->id,
                'verdict' => $verdict,
            ]);

            return ['verdict' => $verdict, 'alerted' => false, 'reason' => 'no-brand'];
        }

        $organizationId = Organization::query()
            ->where('console_organization_id', $session->branch?->console_organization_id)
            ->value('id');

        if ($organizationId === null) {
            Log::warning('cash-drawer.no-organization-mirror', [
                'till_session_id' => (string) $session->id,
            ]);

            return ['verdict' => $verdict, 'alerted' => false, 'reason' => 'no-organization'];
        }

        try {
            $this->dispatcher->toRole(
                new NotificationRequest(
                    type: 'till.cash_drawer_variance',
                    params: [
                        'till_session_id' => (string) $session->id,
                        'branch_name' => (string) ($session->branch?->name ?? ''),
                        'verdict' => $verdict,
                        'machine_variance_minor' => $outcome['machine_variance_minor'],
                        'human_variance_minor' => $outcome['human_variance_minor'],
                        'bill_reject_count' => $outcome['bill_reject_count'],
                    ],
                    organizationId: (string) $organizationId,
                    subject: $session,
                    // MỘT CA MỘT LẦN — xem docblock lớp.
                    idempotencyKey: 'cash-drawer-variance:'.$session->id,
                    priority: 'high',
                    aggregationKey: 'cash-drawer:'.($session->branch_id ?? 'unknown'),
                ),
                // Role là CẤU HÌNH, không hardcode — ai chịu trách nhiệm tiền
                // mặt khác nhau theo tổ chức. Từ vựng thật là
                // `RoleTemplateMatrix::ROLES`, TOÀN GẠCH NGANG; slug sai KHÔNG
                // ném lỗi, nó phân giải ra 0 người và im lặng mãi mãi (#2451).
                role: (string) config('payments.cash_drawer.alert_role', 'shop-manager'),
                scopeKey: 'organization_id',
                scopeId: (string) $organizationId,
                brand: $brand,
            );

            return ['verdict' => $verdict, 'alerted' => true, 'reason' => null];
        } catch (\Throwable $e) {
            Log::warning('cash-drawer.alert-failed', [
                'till_session_id' => (string) $session->id,
                'verdict' => $verdict,
                'error' => $e->getMessage(),
            ]);

            return ['verdict' => $verdict, 'alerted' => false, 'reason' => 'dispatch-failed'];
        }
    }

    /**
     * ⚠️ `branches` KHÔNG có `brand_id` — chỉ `console_brand_id` (#2847).
     */
    private function brandFor(TillSession $session): ?Brand
    {
        $consoleBrandId = $session->branch?->console_brand_id;

        if ($consoleBrandId === null) {
            return null;
        }

        return Brand::query()->where('console_brand_id', $consoleBrandId)->first();
    }
}
