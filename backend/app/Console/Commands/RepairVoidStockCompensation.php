<?php

namespace App\Console\Commands;

use App\Models\CustomerOrderItem;
use App\Omnify\Enums\OrderItemStatusEnum;
use App\Services\Inventory\Contracts\VoidReasonStockEffects;
use App\Services\Inventory\StockDeductionService;
use App\Services\Order\StockDriftAlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * #1205 — find (and optionally repair) voided lines whose stock was never
 * returned to the shelf.
 *
 * Voiding a line that had already deducted stock calls
 * StockDeductionService::compensateVoid inside a try/catch: an inventory
 * failure must not swallow the void itself. That is right, but it left the
 * failure with nowhere to go — `void stock compensation failed` was logged and
 * nothing read it, so the material stayed deducted forever and the drift only
 * surfaced at a physical count.
 *
 * Repair is safe here, unlike the return-invoice equivalent (#1204):
 *   - compensation works off the OUTSTANDING deduction rows, so re-running a
 *     line that was already compensated finds nothing and no-ops;
 *   - the void reason is persisted on the line (`void_reason_id`), so the
 *     restock / waste / none decision comes out identical to the first attempt
 *     instead of being guessed.
 *
 *   php artisan stock:repair-void-compensation            # dry run, writes nothing
 *   php artisan stock:repair-void-compensation --repair
 *   php artisan stock:repair-void-compensation --repair --days=7
 *
 * Scheduled daily with --repair (#1257). It used to run only when somebody
 * remembered to type it, which meant the ERROR above announced a drift that then
 * sat there until a physical count found it.
 */
#[Signature('stock:repair-void-compensation {--repair : Actually re-run the compensation (default is a dry run)} {--days=30 : Only look at lines voided within this many days}')]
#[Description('#1205 — voided lines whose stock compensation was lost; --repair re-runs it (idempotent).')]
class RepairVoidStockCompensation extends Command
{
    public function handle(
        StockDeductionService $stock,
        VoidReasonStockEffects $voidReasons,
        StockDriftAlertService $driftAlerts,
    ): int {
        $repair = (bool) $this->option('repair');
        $days = max(1, (int) $this->option('days'));

        $candidates = CustomerOrderItem::query()
            ->where('status', OrderItemStatusEnum::Voided->value)
            ->whereNotNull('stock_deducted_at')
            ->where('voided_at', '>=', now()->subDays($days))
            ->orderBy('voided_at')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("No voided line with deducted stock in the last {$days} day(s).");

            return self::SUCCESS;
        }

        // #1731 — cổng nhận id (xem docblock của `hasOutstandingDeduction`).
        $stranded = $candidates->filter(fn (CustomerOrderItem $item): bool => $stock->hasOutstandingDeduction((string) $item->getKey()));

        $this->info(sprintf(
            'voided_with_deduction=%d stranded=%d (%s)',
            $candidates->count(),
            $stranded->count(),
            $repair ? 'repairing' : 'dry run',
        ));

        if ($stranded->isEmpty()) {
            return self::SUCCESS;
        }

        $repaired = 0;
        $failed = 0;

        foreach ($stranded as $item) {
            // #962 — cùng đường tra cứu mà đường void trực tuyến dùng
            // (`EloquentOrderLineStockDeduction`), nên quyết định restock /
            // waste / không rõ ra y hệt lần chạy đầu.
            $reason = $item->void_reason_id === null
                ? null
                : $voidReasons->find((string) $item->void_reason_id);

            $this->line(sprintf(
                '  order=%s item=%s voided_at=%s reason=%s',
                $item->customer_order_id,
                $item->getKey(),
                (string) $item->voided_at,
                $reason?->id ?? '(none)',
            ));

            if (! $repair) {
                continue;
            }

            try {
                // #1605 — cổng nhận id; service tự nạp lại dòng, tức chính cái
                // `->fresh()` mà chỗ này vẫn luôn làm, chỉ là làm ở một chỗ.
                $stock->compensateVoid((string) $item->getKey(), $reason);
                $repaired++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf('    repair failed: %s', $e->getMessage()));
                // Tagged, because this now runs on the scheduler (#1257). A
                // compensation that fails for a real reason rather than a
                // transient one will fail again every night, and `#1205:` is
                // not a prefix alerting matches — so it would fail in silence
                // exactly as long as nobody happened to read the file.
                Log::error('[inventory.stock_drift] void stock compensation repair failed', [
                    'item_id' => $item->getKey(),
                    'order_id' => $item->customer_order_id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                // #2697 — lệnh này chạy theo lịch, tức nó thất bại lúc 3 giờ
                // sáng khi không ai nhìn màn hình. `$this->error()` ở trên chỉ
                // tới được người ĐANG gõ lệnh; đường tới người phải sửa là đây.
                $order = $item->customerOrder;

                if ($order !== null) {
                    $driftAlerts->raise(
                        $order,
                        StockDriftAlertService::STAGE_VOID_REPAIR,
                        $e->getMessage(),
                        (string) $item->getKey(),
                    );
                }
            }
        }

        if (! $repair) {
            $this->comment('Dry run — nothing written. Re-run with --repair.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Repaired %d line(s), %d still failing.', $repaired, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
