<?php

namespace App\Console\Commands;

use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Support\ZeroDecimalCurrency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Đơn nào đã thu NHIỀU HƠN tổng của nó? (#1994)
 *
 * Sinh ra từ #1988: một đơn ¥715 bị thu ¥2.860 — bốn giao dịch thẻ thật trong 96
 * giây. Chuỗi lỗi ở đó (workstation gửi `terminal_response` quá dài → Cloud 422 →
 * dead-letter → Cloud không biết thẻ đã trừ → đơn vẫn mở → thu ngân quẹt lại)
 * KHÔNG để lại dấu hiệu nào trên đơn. Nó chỉ lộ ra khi cộng tiền đã thu rồi so
 * với tổng, và trước lệnh này không có gì làm việc đó.
 *
 * ## Vì sao KHÔNG tự viết lại phép cộng
 *
 * Dùng đúng bộ lọc của `OrderPayment::netCollectedForOrder()` — cùng định nghĩa
 * với nguồn chân lý của `customer_orders.paid_amount`. Hai định nghĩa về "đã thu
 * bao nhiêu" là cách sinh ra một con số xanh nói dối: lệnh sẽ báo sạch trong khi
 * sổ nói khác, hoặc ngược lại, và không ai biết bên nào đúng.
 *
 * Cụ thể bộ lọc đó gồm:
 *   - `status` ∈ {succeeded, refunded} — hàng hoàn tiền mang số ÂM nên phải được
 *     cộng vào, nếu không một đơn đã hoàn đủ vẫn bị báo là thu thừa;
 *   - loại hàng có `metadata->settles_payment_id` — chúng thanh toán cho một
 *     payment khác, cộng vào là đếm hai lần.
 *
 * ## Ngưỡng
 *
 * So bằng BƯỚC TIỀN TỆ, không bằng `>`: JPY bước 1, còn tiền có phần lẻ thì bước
 * 0.01. Dùng `>` trần sẽ biến mọi sai số dấu phẩy động thành một "sự cố tiền" và
 * người ta học cách phớt lờ lệnh này.
 *
 * ## Ranh giới
 *
 * CHỈ ĐỌC. Không tự hoàn tiền — hoàn ở gateway là tiền thật rời tài khoản và phải
 * do người quyết. Lệnh cũng KHÔNG đoán nguyên nhân: nó trả lời "đơn nào lệch bao
 * nhiêu", còn #1988 chỉ là một trong nhiều đường dẫn tới đó.
 */
class AuditOverCollectedOrders extends Command
{
    protected $signature = 'payments:audit-overcollection
        {--since= : Chỉ xét đơn tạo từ ngày này (Y-m-d)}
        {--branch= : Giới hạn một chi nhánh}
        {--json : In JSON cho cron}
        {--strict : Thoát khác 0 khi có phát hiện}';

    protected $description = 'Tìm đơn có tiền thu ròng VƯỢT tổng đơn (#1988/#1994) — chỉ đọc';

    public function handle(): int
    {
        $rows = $this->findOverCollected();

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'generated_at' => now()->toIso8601String(),
                'finding_count' => count($rows),
                'total_over_collected' => round(array_sum(array_column($rows, 'over_by')), 2),
                'orders' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($rows);
        }

        if ($rows === []) {
            $this->info('Không đơn nào thu vượt tổng trong phạm vi đã xét.');

            return self::SUCCESS;
        }

        $this->error(sprintf('%d đơn THU THỪA — cần void/refund THỦ CÔNG phía gateway:', count($rows)));
        $this->table(
            ['Đơn', 'Chi nhánh', 'Tổng', 'Đã thu', 'Thừa', 'Số GD', 'Lần thu đầu → cuối'],
            array_map(static fn (array $r): array => [
                $r['order_id'],
                $r['branch_id'],
                $r['total'],
                $r['collected'],
                $r['over_by'],
                $r['payment_count'],
                $r['first_paid_at'].' → '.$r['last_paid_at'],
            ], $rows),
        );

        foreach ($rows as $r) {
            if ($r['refs'] !== []) {
                $this->line('  '.$r['order_id'].' → tham chiếu gateway: '.implode(' · ', $r['refs']));
            }
        }

        $this->newLine();
        $this->warn('Lệnh này KHÔNG hoàn tiền. Hoàn ở gateway là tiền thật rời tài khoản — người quyết.');

        return $this->exitCode($rows);
    }

    /** @return list<array<string, mixed>> */
    private function findOverCollected(): array
    {
        $query = CustomerOrder::query()->select(['id', 'branch_id', 'total_amount', 'created_at']);

        if (($since = $this->option('since')) !== null && $since !== '') {
            $query->where('created_at', '>=', $since);
        }

        if (($branch = $this->option('branch')) !== null && $branch !== '') {
            $query->where('branch_id', $branch);
        }

        // Chỉ xét đơn CÓ thanh toán — đơn chưa trả gì thì không thể thu thừa, và
        // bỏ chúng ra khiến lệnh chạy được trên bảng lớn.
        $query->whereHas('payments');

        $rows = [];
        $currencyByBranch = [];

        $query->orderBy('created_at')->chunkById(500, function ($orders) use (&$rows, &$currencyByBranch): void {
            foreach ($orders as $order) {
                $collected = OrderPayment::netCollectedForOrder((string) $order->id);
                $total = (float) $order->total_amount;
                // `customer_orders` KHÔNG mang tiền tệ — nó nằm ở chi nhánh
                // (`branches.currency`). Đọc nhầm chỗ thì bước tiền tệ sai và mọi
                // sai số dấu phẩy động biến thành "sự cố tiền".
                $currency = $currencyByBranch[(string) $order->branch_id] ??= (string) DB::table('branches')
                    ->where('id', $order->branch_id)
                    ->value('currency');
                $step = ZeroDecimalCurrency::contains($currency) ? 1.0 : 0.01;

                if ($collected - $total < $step) {
                    continue;
                }

                $payments = OrderPayment::query()
                    ->where('customer_order_id', $order->id)
                    ->whereNull('metadata->settles_payment_id')
                    ->whereIn('status', [PaymentStatusEnum::Succeeded->value, PaymentStatusEnum::Refunded->value])
                    ->orderBy('created_at')
                    ->get(['id', 'amount', 'status', 'paid_at', 'created_at', 'reference_no', 'payment_code']);

                $rows[] = [
                    'order_id' => (string) $order->id,
                    'branch_id' => (string) $order->branch_id,
                    'currency' => $currency,
                    'total' => round($total, 2),
                    'collected' => round($collected, 2),
                    'over_by' => round($collected - $total, 2),
                    'payment_count' => $payments->count(),
                    'first_paid_at' => (string) ($payments->first()?->paid_at ?? $payments->first()?->created_at),
                    'last_paid_at' => (string) ($payments->last()?->paid_at ?? $payments->last()?->created_at),
                    // Tham chiếu để void/refund PHÍA GATEWAY — không có nó thì
                    // báo cáo chỉ nói "có sự cố" mà không ai hành động được.
                    // `reference_no` là tham chiếu PHÍA GATEWAY (thứ dùng để void
                    // /refund ở SBPS/Stripe/PayPay); `payment_code` là mã nội bộ,
                    // giữ lại để đối chiếu sổ. Không có hai thứ này thì báo cáo
                    // chỉ nói "có sự cố" mà không ai hành động được.
                    'refs' => $payments
                        ->map(static fn ($p): string => trim(($p->reference_no ?: '—').' ('.$p->payment_code.')'))
                        ->values()
                        ->all(),
                ];
            }
        });

        return $rows;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function exitCode(array $rows): int
    {
        return $this->option('strict') && $rows !== [] ? self::FAILURE : self::SUCCESS;
    }
}
