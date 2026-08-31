<?php

namespace App\Services\Printing;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * plan-052 M2 / T2.3 — tell a shop manager when the print pipeline needs a
 * human, and then SHUT UP until something changes.
 *
 * Three conditions are worth a manager's phone:
 *
 *   - a printer has gone quiet past its threshold (the power strip, usually);
 *   - `needs_attention` jobs have piled past the configured backlog;
 *   - a MONEY document failed — from the first one, because there is no
 *     acceptable number of receipts a shop silently fails to print (PR1).
 *
 * **Debounce (plan-050 G4).** `Cache::add` is the gate — atomic, so two
 * workers ticking at once produce one alert, not two. Two properties follow,
 * and the second is the one people get wrong:
 *
 *   1. while the condition holds, one alert per window;
 *   2. when the condition CLEARS, the cooldown is released. A problem that was
 *      fixed and came back is news again. Leaving the key to expire on its own
 *      would swallow the second occurrence for no reason other than
 *      bookkeeping — and the second occurrence of a printer dying twice in an
 *      evening is exactly what a manager wants to hear about.
 *
 * Every dispatch is best-effort: a notification platform hiccup must never
 * abort the sweep that found the problem.
 */
class PrintPipelineAlertService
{
    public const TYPE_PRINTER_SILENT = 'print.printer_silent';

    public const TYPE_NEEDS_ATTENTION = 'print.jobs_need_attention';

    public const TYPE_MONEY_DOCUMENT_FAILED = 'print.money_document_failed';

    public function __construct(
        private readonly PrintJobAgingService $aging,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * @return array{emitted: list<array<string, mixed>>, suppressed: list<array<string, mixed>>, cleared: list<string>}
     */
    public function sweep(?string $branchId = null, ?CarbonImmutable $now = null): array
    {
        $result = ['emitted' => [], 'suppressed' => [], 'cleared' => []];

        if (! (bool) config('print_jobs.alerts.enabled', true)) {
            return $result;
        }

        $now ??= CarbonImmutable::now();

        foreach ($this->branchesInScope($branchId) as $branch) {
            try {
                $this->sweepBranch($branch, $now, $result);
            } catch (\Throwable $e) {
                Log::warning('print_alerts_branch_failed', [
                    'branch_id' => $branch->id,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * @param  array{emitted: list<array<string, mixed>>, suppressed: list<array<string, mixed>>, cleared: list<string>}  $result
     */
    private function sweepBranch(Branch $branch, CarbonImmutable $now, array &$result): void
    {
        $branchId = (string) $branch->id;

        // ── 1. Silent printers — debounced PER PRINTER ────────────────────
        //
        // Per printer, not per branch: three dead machines in a food court is
        // three different repairs, and collapsing them into one message means
        // the second and third get fixed a day later. The per-printer cooldown
        // is what stops the every-tick repetition.
        $silent = $this->aging->silentPrinters($branchId, $now);
        $silentIds = [];

        foreach ($silent as $printer) {
            $silentIds[] = $printer['printer_id'];
            $key = $this->cooldownKey('printer-silent', $printer['printer_id']);

            if (! $this->claim($key)) {
                $result['suppressed'][] = ['type' => self::TYPE_PRINTER_SILENT, 'printer_id' => $printer['printer_id']];

                continue;
            }

            $this->dispatch($branch, self::TYPE_PRINTER_SILENT, [
                'printer_name' => $printer['printer_name'],
                'shop_name' => (string) ($branch->name ?? ''),
                'minutes' => (string) (int) round($printer['silent_for_seconds'] / 60),
                'detection' => $printer['detection'],
            ], "print.printer_silent:{$printer['printer_id']}:".$now->format('YmdH'));

            $result['emitted'][] = array_merge(['type' => self::TYPE_PRINTER_SILENT], $printer);
        }

        // A printer that started talking again releases its cooldown, so the
        // NEXT outage is reported immediately rather than at window + 1.
        foreach ($this->activePrinterIds($branchId) as $printerId) {
            if (in_array($printerId, $silentIds, true)) {
                continue;
            }

            if ($this->release($this->cooldownKey('printer-silent', $printerId))) {
                $result['cleared'][] = $this->cooldownKey('printer-silent', $printerId);
            }
        }

        // ── 2. needs_attention backlog — debounced per branch ─────────────
        $threshold = max(1, (int) config('print_jobs.alerts.needs_attention_threshold', 5));
        $backlog = PrintJob::query()
            ->where('branch_id', $branchId)
            ->where('status', PrintJobStatus::NeedsAttention->value)
            ->count();

        $this->conditionAlert(
            $branch,
            $backlog >= $threshold,
            $this->cooldownKey('needs-attention', $branchId),
            self::TYPE_NEEDS_ATTENTION,
            [
                'shop_name' => (string) ($branch->name ?? ''),
                'count' => (string) $backlog,
                'threshold' => (string) $threshold,
            ],
            "print.jobs_need_attention:{$branchId}:".$now->format('YmdH'),
            ['count' => $backlog, 'threshold' => $threshold],
            $result,
        );

        // ── 3. Money documents that did not come out ──────────────────────
        $moneyThreshold = max(1, (int) config('print_jobs.alerts.money_document_threshold', 1));
        $lookback = $now->subHours(max(1, (int) config('print_jobs.alerts.money_document_lookback_hours', 24)));

        $moneyFailed = PrintJob::query()
            ->where('branch_id', $branchId)
            ->whereIn('kind', array_values(array_map(
                static fn (PrintJobKind $k): string => $k->value,
                array_filter(PrintJobKind::cases(), static fn (PrintJobKind $k): bool => $k->isMoneyDocument()),
            )))
            ->whereIn('status', [PrintJobStatus::Failed->value, PrintJobStatus::NeedsAttention->value])
            ->where('created_at', '>=', $lookback)
            // A job a manager has already ruled on is not an open alert.
            ->whereDoesntHave('resolution')
            ->count();

        $this->conditionAlert(
            $branch,
            $moneyFailed >= $moneyThreshold,
            $this->cooldownKey('money-document', $branchId),
            self::TYPE_MONEY_DOCUMENT_FAILED,
            [
                'shop_name' => (string) ($branch->name ?? ''),
                'count' => (string) $moneyFailed,
            ],
            "print.money_document_failed:{$branchId}:".$now->format('YmdH'),
            ['count' => $moneyFailed, 'threshold' => $moneyThreshold],
            $result,
        );
    }

    /**
     * @param  array<string, string>  $params
     * @param  array<string, mixed>  $context
     * @param  array{emitted: list<array<string, mixed>>, suppressed: list<array<string, mixed>>, cleared: list<string>}  $result
     */
    private function conditionAlert(
        Branch $branch,
        bool $breached,
        string $key,
        string $type,
        array $params,
        string $idempotencyKey,
        array $context,
        array &$result,
    ): void {
        if (! $breached) {
            if ($this->release($key)) {
                $result['cleared'][] = $key;
            }

            return;
        }

        if (! $this->claim($key)) {
            $result['suppressed'][] = array_merge(['type' => $type, 'branch_id' => (string) $branch->id], $context);

            return;
        }

        $this->dispatch($branch, $type, $params, $idempotencyKey);

        $result['emitted'][] = array_merge(['type' => $type, 'branch_id' => (string) $branch->id], $context);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function dispatch(Branch $branch, string $type, array $params, string $idempotencyKey): void
    {
        try {
            $brand = $branch->brand ?? Brand::query()
                ->where('console_brand_id', $branch->console_brand_id)
                ->first();

            $organizationId = $this->organizationIdFor($branch);

            if ($brand === null || $organizationId === null) {
                return;
            }

            $this->notifications->toRole(
                new NotificationRequest(
                    type: $type,
                    params: $params,
                    organizationId: (string) $organizationId,
                    subject: $branch,
                    idempotencyKey: $idempotencyKey,
                    priority: 'high',
                ),
                // #2849 — `shop-manager` MỘT MÌNH là im lặng tuyệt đối trên
                // production: cả 4 người ở đó giữ `tempo-admin` (→ `org-admin`),
                // không ai giữ `shop-manager`/`tempo-manager`, nên audience
                // phân giải ra 0 người và cảnh báo máy in chết câm. Đo được:
                // 34 lần `print_alert_dispatch_skipped` trong hai ngày 08-13/14,
                // toàn bộ là `print.printer_silent` ở 本郷店.
                //
                // Thứ bậc vai KHÔNG tự bắc sang chuyện gửi thông báo — policy
                // coi org-admin là shop-manager, audience thì không — nên phải
                // khai tường minh, đúng khuôn #2450 đã chốt cho đơn hàng.
                //
                // Người quán vẫn đứng TRƯỚC: đây là việc họ xử lý được tại chỗ.
                // `org-admin` là lưới hứng cho quán chưa có ai giữ vai quản lý,
                // và `coversBranch()` cho phép họ vào qua `branch_id IS NULL`
                // (= all_branches_access, #2460).
                //
                // #2859 — đánh đổi ĐÃ BIẾT, ghi ở đây để lần sau không phải đo lại:
                // org-admin nhận cảnh báo in của MỌI chi nhánh (đó chính là nghĩa
                // của `branch_id IS NULL`), và debounce ở đây tính theo TỪNG máy
                // in chứ không gom theo tổ chức. Với một quán thì đúng; org nhiều
                // quán sẽ thành ồn, và ồn thì người ta tắt kênh — lúc đó mất luôn
                // cả cảnh báo thật.
                //
                // Chỗ sửa khi tới lúc là thêm `aggregationKey` cho lượt dispatch
                // này (gom theo tổ chức + loại, giữ debounce per-printer cho
                // người quán). Chưa làm vì hiện chỉ có một quán, và một khoá gom
                // đặt sai còn nuốt mất cảnh báo của quán thứ hai.
                role: ['shop-manager', 'org-admin'],
                scopeKey: 'branch_id',
                scopeId: (string) $branch->getKey(),
                brand: $brand,
            );
        } catch (\Throwable $e) {
            // An empty audience (nobody holds shop-manager at this branch yet)
            // throws here. That is a configuration gap, not a reason to abort a
            // sweep that is reporting a broken printer.
            Log::warning('print_alert_dispatch_skipped', [
                'branch_id' => $branch->id,
                'type' => $type,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    // =====================================================================
    //  Debounce
    // =====================================================================

    private function cooldownKey(string $alert, string $entityId): string
    {
        return "printing:{$alert}:cooldown:{$entityId}";
    }

    /** True when THIS caller won the window (i.e. may alert). */
    private function claim(string $key): bool
    {
        $minutes = (int) config('print_jobs.alerts.debounce_minutes', 120);

        if ($minutes <= 0) {
            return true;
        }

        return Cache::add($key, CarbonImmutable::now()->toIso8601String(), CarbonImmutable::now()->addMinutes($minutes));
    }

    /** Re-arm: returns true when a cooldown was actually standing. */
    private function release(string $key): bool
    {
        if (! Cache::has($key)) {
            return false;
        }

        Cache::forget($key);

        return true;
    }

    // =====================================================================
    //  Scope helpers
    // =====================================================================

    /** @return Collection<int, Branch> */
    private function branchesInScope(?string $branchId)
    {
        if ($branchId !== null && trim($branchId) !== '') {
            return Branch::query()->whereKey($branchId)->get();
        }

        $ids = Printer::query()->distinct()->pluck('branch_id')
            ->merge(PrintJob::query()->distinct()->pluck('branch_id'))
            ->filter()
            ->unique()
            ->values();

        return Branch::query()->whereIn('id', $ids)->get();
    }

    /** @return list<string> */
    private function activePrinterIds(string $branchId): array
    {
        return Printer::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    private function organizationIdFor(Branch $branch): ?string
    {
        $id = PrintJob::query()->where('branch_id', $branch->id)->value('organization_id')
            ?? Printer::query()->where('branch_id', $branch->id)->value('organization_id');

        return $id === null ? null : (string) $id;
    }
}
