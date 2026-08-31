<?php

declare(strict_types=1);

namespace App\Services\Payment\Settlement;

use App\Models\Brand;
use App\Models\PaymentGatewayConnection;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Support\Logging\MoneyOrchestrationLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * plan-050 T4.3 — biến kết quả đối soát thành cảnh báo cho người vận hành.
 *
 * ## Vì sao là một lớp riêng, không nhét vào `SettlementReconciliationService`
 *
 * Đối soát là phép ĐO; cảnh báo là quyết định GỬI. Trộn hai thứ làm mọi test
 * đối soát phải dựng thêm hạ tầng thông báo, và làm một lượt `--dry-run` gửi
 * thật. Ở đây `raise()` nhận kết quả đã đo và chỉ quyết định gửi cái gì.
 *
 * ## G4 — chống nhiễu, vì alert nhiễu là alert bị bỏ qua
 *
 * `RISKS.md` xếp G4 (alert noise) là rủi ro Cao. Ba cơ chế, không cái nào là
 * tuỳ chọn:
 *
 * 1. **Re-match TRƯỚC khi kêu.** Orphan phần lớn là do payment offline đồng bộ
 *    muộn (S-19) — kêu ngay thì gần như luôn là báo động giả. Lớp này CỐ Ý chỉ
 *    nhận đầu vào sau khi `reconcile()` đã chạy xong vòng re-match.
 * 2. **Ngưỡng theo provider, lấy từ cấu hình.** Stripe trả tiền theo ngày,
 *    PayPay theo chu kỳ tháng — một ngưỡng chung sẽ hoặc câm với Stripe hoặc
 *    la hét với PayPay.
 * 3. **Chặn lặp theo NGÀY.** `idempotency_key` mang ngày nghiệp vụ, nên một
 *    tình trạng kéo dài một tuần kêu 7 lần, không phải 7×số lần chạy lệnh.
 *
 * ## Mọi cảnh báo phải HÀNH ĐỘNG ĐƯỢC
 *
 * Cũng theo G4: kèm connection, số tiền, và lệnh gợi ý. Một dòng "có sai lệch"
 * không nói được người đọc phải làm gì tiếp, và nó sẽ bị bỏ qua đúng như alert
 * nhiễu — chỉ là bỏ qua vì bất lực thay vì vì mệt.
 *
 * ## Không tự cân sổ
 *
 * S-12 nói rõ: payout lệch thì để lệch, kêu lên. Lớp này chỉ đọc, không sửa
 * một đồng nào — nếu ngày nào nó bắt đầu "sửa nhẹ cho khớp", đó là lúc mất khả
 * năng đối chiếu với sao kê của gateway.
 */
final class SettlementAlertService
{
    /** Trần số dòng chi tiết nhét vào một thông báo. */
    private const SAMPLE_LIMIT = 5;

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    /**
     * @param  array{
     *     unsettled_payments?: list<array<string, mixed>>,
     *     orphans?: list<array<string, mixed>>,
     *     mismatch_payouts?: list<array<string, mixed>>,
     *     mismatch_rows?: list<array<string, mixed>>,
     * }  $reconciliation kết quả `SettlementReconciliationService::reconcile()`
     * @param  list<array<string, mixed>>  $aging  kết quả `SettlementAgingReportService::pendingPayoutAging()`
     * @return list<array{type: string, connection_id: string, count: int, sent: bool, reason?: string}>
     */
    public function raise(array $reconciliation, array $aging, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $day = $now->toDateString();

        $raised = [];

        foreach ($this->groupByConnection($reconciliation, $aging) as $connectionId => $groups) {
            foreach ($groups as $type => $rows) {
                if ($rows === []) {
                    continue;
                }

                $raised[] = $this->send($type, (string) $connectionId, $rows, $day);
            }
        }

        return $raised;
    }

    /**
     * Gom theo CONNECTION trước khi gửi.
     *
     * Một lượt `settlements:reconcile` chạy trên nhiều connection. Gửi theo
     * từng dòng thì một ngày lệch 40 dòng thành 40 thông báo — đúng cái G4
     * cấm. Gom theo (connection, loại) cho ra tối đa 4 thông báo mỗi connection
     * mỗi ngày.
     *
     * Gom theo connection chứ không theo tổ chức vì `connection_id` là trường
     * DUY NHẤT mà cả `reconcile()` lẫn `pendingPayoutAging()` cùng trả về —
     * không dòng nào trong hai nguồn mang `organization_id`. Tổ chức và brand
     * được tra ngược từ connection ở bước gửi.
     *
     * @param  array<string, mixed>  $reconciliation
     * @param  list<array<string, mixed>>  $aging
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    private function groupByConnection(array $reconciliation, array $aging): array
    {
        $grouped = [];

        $feed = [
            'settlement.orphan_overdue' => $reconciliation['orphans'] ?? [],
            'settlement.payout_mismatch' => $reconciliation['mismatch_payouts'] ?? [],
            'settlement.payment_unsettled' => $reconciliation['unsettled_payments'] ?? [],
            // Chỉ những dòng ĐÃ vượt ngưỡng per-provider. `pendingPayoutAging()`
            // trả cả những dòng còn trong hạn — chúng là trạng thái bình thường
            // của một khoản chờ chi trả, không phải sự cố.
            'settlement.payout_aging' => array_values(array_filter(
                $aging,
                static fn (array $row): bool => ($row['over_threshold'] ?? false) === true,
            )),
        ];

        foreach ($feed as $type => $rows) {
            foreach ($rows as $row) {
                $connectionId = $this->connectionIdOf($row);

                if ($connectionId === null) {
                    // Không quy được về connection thì KHÔNG im lặng bỏ qua:
                    // một dòng lệch không có chủ vẫn là một dòng lệch.
                    Log::warning('settlement-alert.unattributable-row', [
                        'type' => $type,
                        'row' => $row,
                    ]);

                    continue;
                }

                $grouped[$connectionId][$type][] = $row;
            }
        }

        return $grouped;
    }

    /** @param array<string, mixed> $row */
    private function connectionIdOf(array $row): ?string
    {
        $id = $row['connection_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{type: string, connection_id: string, count: int, sent: bool, reason?: string}
     */
    private function send(string $type, string $connectionId, array $rows, string $day): array
    {
        $result = [
            'type' => $type,
            'connection_id' => $connectionId,
            'count' => count($rows),
            'sent' => false,
        ];

        $connection = PaymentGatewayConnection::query()->whereKey($connectionId)->first();

        if ($connection === null) {
            // Connection biến mất giữa lúc đối soát và lúc gửi (bị thu hồi —
            // S-20). Số liệu vẫn nằm trong kết quả trả về của lệnh, nên nó
            // không mất; chỉ là không có ai để gửi tới.
            Log::warning('settlement-alert.unknown-connection', [
                'connection_id' => $connectionId,
                'type' => $type,
                'count' => count($rows),
            ]);

            return $result + ['reason' => 'unknown-connection'];
        }

        $organizationId = (string) $connection->organization_id;
        $brand = Brand::query()->whereKey($connection->brand_id)->first();

        if ($brand === null) {
            // `toRole` giải audience trong phạm vi một brand, nên thiếu brand
            // là không gửi được. Ghi lại thay vì nuốt.
            Log::warning('settlement-alert.no-brand-for-connection', [
                'connection_id' => $connectionId,
                'organization_id' => $organizationId,
                'type' => $type,
                'count' => count($rows),
            ]);

            return $result + ['reason' => 'no-brand'];
        }

        try {
            $this->dispatcher->toRole(
                new NotificationRequest(
                    type: $type,
                    params: $this->params($type, $rows) + ['connection_id' => $connectionId],
                    organizationId: $organizationId,
                    subject: $connection,
                    // Ngày nghiệp vụ nằm TRONG khoá: một tình trạng kéo dài kêu
                    // mỗi ngày một lần, không phải mỗi lượt chạy một lần. Đây là
                    // vế chống-nhiễu thứ ba của G4.
                    idempotencyKey: "{$type}:{$connectionId}:{$day}",
                    priority: 'high',
                    // Gộp mọi cảnh báo settlement của một connection trong ngày
                    // thành MỘT dòng chuông.
                    aggregationKey: "settlement:{$connectionId}:{$day}",
                ),
                // Role là CẤU HÌNH, không hardcode: ai chịu trách nhiệm đối soát
                // tiền khác nhau theo tổ chức, và đây là quyết định vận hành.
                role: (string) config('payments.settlement.alert_role', 'org-admin'),
                scopeKey: 'organization_id',
                scopeId: $organizationId,
                brand: $brand,
            );

            $result['sent'] = true;
        } catch (\Throwable $e) {
            // Cảnh báo hỏng KHÔNG được làm hỏng đối soát. Đối soát là thứ ghi
            // dữ liệu; cảnh báo chỉ là thông báo về nó. Audience rỗng (chưa ai
            // giữ role đó) cũng rơi vào đây và cũng không được chặn gì.
            // #2864 — một cảnh báo TIỀN không tới được ai là sự cố của chính
            // hệ thống cảnh báo, không phải một dòng thống kê.
            //
            // Đo trên production: `settlement.orphan_overdue` chạy 21:30 UTC và
            // hỏng ĐÚNG 5 ĐÊM LIỀN (2026-08-10 → 08-14) với cùng một câu
            // "requires at least one recipient", trong khi ¥366.643 tiền lạ
            // đang dày lên trong sổ. Không ai thấy, vì `warning` là mức người
            // ta lướt qua.
            //
            // Đi qua `MoneyOrchestrationLog::error()` chứ KHÔNG ghi mức nặng
            // thẳng vào kênh payment_orchestration: kênh đó là file daily
            // riêng, KHÔNG nằm trong `stack`, nên một dòng ghi thẳng vào đó
            // báo động không bao giờ thấy (#1244). Bản đầu của chính vá này
            // viết sai đúng kiểu đó và `MoneyFailOpenLogsAreAlertableTest`
            // bắt được — rào làm đúng việc của nó.
            //
            // (Và đừng chép nguyên dạng lời gọi sai vào comment để minh hoạ:
            // bộ quét đếm cả khớp trong comment, nên chính dòng giải thích sẽ
            // tự tính thành một vi phạm. Cũng đã trả giá ngay tại đây.)
            //
            // Vẫn KHÔNG ném: fail-open giữ nguyên, đối soát phải chạy xong.
            MoneyOrchestrationLog::error(
                MoneyOrchestrationLog::TAG_SETTLEMENT,
                'settlement_money_alert_reached_nobody',
                [
                    'type' => $type,
                    'connection_id' => $connectionId,
                    'organization_id' => $organizationId,
                    'rows' => count($rows),
                    'error' => $e->getMessage(),
                ],
            );

            Log::warning('settlement-alert.dispatch-failed', [
                'type' => $type,
                'connection_id' => $connectionId,
                'error' => $e->getMessage(),
            ]);

            $result['reason'] = 'dispatch-failed';
        }

        return $result;
    }

    /**
     * Nội dung thông báo — phải trả lời được "giờ tôi làm gì".
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function params(string $type, array $rows): array
    {
        $connections = array_values(array_unique(array_filter(array_map(
            static fn (array $row): ?string => is_string($row['connection_id'] ?? null) ? $row['connection_id'] : null,
            $rows,
        ))));

        return [
            'count' => count($rows),
            'connections' => $connections,
            'total_minor' => $this->sumMinor($rows),
            'currencies' => array_values(array_unique(array_filter(array_map(
                static fn (array $row): ?string => is_string($row['currency'] ?? null) ? $row['currency'] : null,
                $rows,
            )))),
            'samples' => array_slice($rows, 0, self::SAMPLE_LIMIT),
            'truncated' => count($rows) > self::SAMPLE_LIMIT,
            'suggested_command' => $this->suggestedCommand($type, $connections),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function sumMinor(array $rows): int
    {
        $total = 0;

        foreach ($rows as $row) {
            foreach (['net_minor', 'total_net_minor', 'amount_minor', 'gross_minor'] as $key) {
                if (isset($row[$key]) && is_numeric($row[$key])) {
                    $total += (int) $row[$key];

                    break;
                }
            }
        }

        return $total;
    }

    /** @param list<string> $connections */
    private function suggestedCommand(string $type, array $connections): string
    {
        $scope = count($connections) === 1 ? " --connection={$connections[0]}" : '';

        return match ($type) {
            // Chạy lại đối soát là bước đầu đúng cho cả ba: nó re-match orphan
            // và in ra danh sách đầy đủ mà thông báo đã phải cắt bớt.
            'settlement.orphan_overdue',
            'settlement.payment_unsettled',
            'settlement.payout_mismatch' => "php artisan settlements:reconcile{$scope} --dry-run",
            // Aging không sửa được bằng lệnh — nó là tiền gateway chưa chi. Việc
            // đúng là đối chiếu với cổng quản trị của provider.
            'settlement.payout_aging' => 'đối chiếu lịch chi trả trên cổng quản trị của provider'.($scope !== '' ? " ({$connections[0]})" : ''),
            default => "php artisan settlements:reconcile{$scope} --dry-run",
        };
    }
}
