<?php

namespace App\Services\Printing;

use App\Models\Branch;
use App\Models\PrintJob;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * plan-052 M2 / T2.1 — the reconciliation sweep behind `print-jobs:reconcile`.
 *
 * ONE rule shapes this entire class, and it is DESIGN §1b:
 *
 *   **The queue lives at the tier closest to the printer. Cloud only syncs the
 *   fact "printed or not".**
 *
 * So the sweep has two completely different jobs depending on who owns the
 * queue for a row:
 *
 *   - `ws_lan` — the WORKSTATION owns it. Cloud may look, count and report;
 *     Cloud may not schedule, retry, expire or transition ANYTHING. Not "does
 *     not today" — must not, ever: the day Cloud started moving those rows,
 *     the ledger would hold a second opinion about a print Cloud did not
 *     perform, and a Cloud outage would silently win over the shop's own
 *     record (RISKS PR2). {@see PrintJob} throws if this class ever slips.
 *   - `cloudprnt` (and the operator-driven epos/webprnt rows of M3) — Cloud IS
 *     the closest tier, so Cloud runs the TTL table here.
 *
 * And one rule cuts across both (RISKS PR1): **a money document is never
 * auto-retried, from any state, on any branch of this code.** There is no
 * re-dispatch path in this class at all — the strongest form of "never" is
 * having nowhere to put it. A stuck receipt becomes `needs_attention` and
 * waits for a person.
 *
 * The sweep is IDEMPOTENT: it computes a target status per row and writes only
 * when that differs from the current one, so a second run in the same second
 * touches nothing (not even `updated_at`).
 */
class PrintJobReconciler
{
    public function __construct(private readonly PrintJobRegistry $registry) {}

    /** Reason codes stamped on `last_error` when the sweep moves a Cloud-owned row. */
    public const REASON_TTL_EXCEEDED = 'reconcile:ttl_exceeded';

    public const REASON_TTL_EXCEEDED_MONEY = 'reconcile:ttl_exceeded_money_document';

    public const REASON_ACK_LOST = 'reconcile:ack_lost';

    /**
     * @param  array{branch_id?: ?string, transport?: ?string, dry_run?: bool}  $options
     * @return array{
     *     dry_run: bool,
     *     scanned: int,
     *     branches: list<array<string, mixed>>,
     *     changes: list<array<string, mixed>>,
     *     findings: list<array<string, mixed>>,
     *     errors: list<array<string, mixed>>,
     * }
     */
    public function reconcile(array $options = []): array
    {
        $branchFilter = $this->nonEmpty($options['branch_id'] ?? null);
        $transportFilter = $this->nonEmpty($options['transport'] ?? null);
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $now = CarbonImmutable::now();
        $lookback = $now->subHours(max(1, (int) config('print_jobs.reconcile.lookback_hours', 168)));

        $report = [
            'dry_run' => $dryRun,
            'scanned' => 0,
            'branches' => [],
            'changes' => [],
            'findings' => [],
            'errors' => [],
        ];

        foreach ($this->branchIdsInScope($branchFilter, $transportFilter, $lookback) as $branchId) {
            try {
                $branchReport = $this->reconcileBranch($branchId, $transportFilter, $dryRun, $now, $lookback);
            } catch (\Throwable $e) {
                // plan-048 lesson: one bad branch must not abort the sweep for
                // every other shop. A shop whose row set trips a bug still
                // deserves its neighbours' reports.
                Log::error('print_jobs_reconcile_branch_failed', [
                    'branch_id' => $branchId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                $report['errors'][] = [
                    'branch_id' => $branchId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ];

                continue;
            }

            $report['scanned'] += $branchReport['scanned'];
            $report['branches'][] = $branchReport['summary'];
            $report['changes'] = array_merge($report['changes'], $branchReport['changes']);
            $report['findings'] = array_merge($report['findings'], $branchReport['findings']);
        }

        return $report;
    }

    /**
     * @return array{scanned: int, summary: array<string, mixed>, changes: list<array<string, mixed>>, findings: list<array<string, mixed>>}
     */
    private function reconcileBranch(
        string $branchId,
        ?string $transportFilter,
        bool $dryRun,
        CarbonImmutable $now,
        CarbonImmutable $lookback,
    ): array {
        $changes = [];
        $findings = [];
        $scanned = 0;

        $counters = [
            'journal_needs_attention' => 0,
            'journal_stale_delivering' => 0,
            'journal_past_ttl_open' => 0,
            'journal_late_sync' => 0,
            'journal_money_reprint_no_reason' => 0,
            'cloud_expired' => 0,
            'cloud_needs_attention' => 0,
            'cloud_untouched' => 0,
        ];

        PrintJob::query()
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', $lookback)
            ->when($transportFilter !== null, fn ($q) => $q->where('transport', $transportFilter))
            // chunkById paginates on the primary key itself — a cursor that
            // cannot skip or repeat a row while the sweep is writing.
            ->chunkById(200, function ($chunk) use (
                &$changes, &$findings, &$scanned, &$counters, $dryRun, $now
            ): void {
                foreach ($chunk as $job) {
                    $scanned++;

                    if ($job->transport === PrintTransport::WsLan) {
                        $this->inspectJournalRow($job, $now, $findings, $counters);

                        continue;
                    }

                    $this->reconcileCloudOwnedRow($job, $now, $dryRun, $changes, $counters);
                }
            });

        return [
            'scanned' => $scanned,
            'summary' => array_merge([
                'branch_id' => $branchId,
                'branch_name' => Branch::query()->whereKey($branchId)->value('name') ?? '(unknown)',
                'scanned' => $scanned,
            ], $counters),
            'changes' => $changes,
            'findings' => $findings,
        ];
    }

    // =====================================================================
    //  ws_lan — DETECTION ONLY (§1b). Not one write happens below this line.
    // =====================================================================

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  array<string, int>  $counters
     */
    private function inspectJournalRow(PrintJob $job, CarbonImmutable $now, array &$findings, array &$counters): void
    {
        $staleAfter = max(1, (int) config('print_jobs.reconcile.stale_delivering_seconds', 300));
        $lateAfter = max(1, (int) config('print_jobs.reconcile.late_journal_seconds', 3600));

        $eventAt = $this->eventTime($job);

        // The workstation printed and told us so — but the batch took hours to
        // land. That is an UPLINK fault, not a printing fault, and saying so is
        // the difference between fixing a router and replacing a printer.
        if ($job->printed_reported_at !== null && $job->created_at !== null) {
            $lagSeconds = $job->printed_reported_at->diffInSeconds($job->created_at, false);

            if ($lagSeconds >= $lateAfter) {
                $counters['journal_late_sync']++;
                $findings[] = $this->finding($job, 'journal_late_sync', [
                    'lag_seconds' => (int) $lagSeconds,
                    'printed_at' => $job->printed_reported_at->toIso8601String(),
                    'synced_at' => $job->created_at->toIso8601String(),
                ]);
            }
        }

        // printing.md §8 promised this report. Since the 2026-07-28 ruling it is
        // the ONLY consequence of reprinting a money document with no reason —
        // the print itself is never refused (§4) — so this line has to be right,
        // and it asks the one rule rather than restating it.
        if ($job->warnedWithoutReason()) {
            $counters['journal_money_reprint_no_reason']++;
            $findings[] = $this->finding($job, 'journal_money_reprint_no_reason', [
                'reprint_no' => $job->reprint_no,
            ]);
        }

        if ($job->status === PrintJobStatus::NeedsAttention) {
            $counters['journal_needs_attention']++;
            $findings[] = $this->finding($job, 'journal_needs_attention', []);

            return;
        }

        if ($job->status === PrintJobStatus::Delivering
            && $eventAt->addSeconds($staleAfter)->lessThanOrEqualTo($now)) {
            $counters['journal_stale_delivering']++;
            $findings[] = $this->finding($job, 'journal_stale_delivering', [
                'stuck_for_seconds' => (int) $eventAt->diffInSeconds($now),
            ]);

            return;
        }

        if (! $job->status->isTerminal() && $this->expiresAt($job)->lessThanOrEqualTo($now)) {
            // Past its TTL and still open. Cloud REPORTS it; only the
            // workstation may decide the ticket is dead (§1b).
            $counters['journal_past_ttl_open']++;
            $findings[] = $this->finding($job, 'journal_past_ttl_open', [
                'expires_at' => $this->expiresAt($job)->toIso8601String(),
            ]);
        }
    }

    // =====================================================================
    //  Cloud-owned queue rows — the only place this class writes.
    // =====================================================================

    /**
     * @param  list<array<string, mixed>>  $changes
     * @param  array<string, int>  $counters
     */
    private function reconcileCloudOwnedRow(
        PrintJob $job,
        CarbonImmutable $now,
        bool $dryRun,
        array &$changes,
        array &$counters,
    ): void {
        if ($job->status->isTerminal()) {
            // `printed`, `failed`, `expired` are done. Yesterday's printed
            // receipt does not become "expired" tomorrow (P-06).
            $counters['cloud_untouched']++;

            return;
        }

        [$target, $reason] = $this->targetFor($job, $now);

        if ($target === null || $target === $job->status) {
            $counters['cloud_untouched']++;

            return;
        }

        $changes[] = [
            'job_id' => $job->id,
            'branch_id' => $job->branch_id,
            'transport' => $job->transport->value,
            'kind' => $job->kind->value,
            'from' => $job->status->value,
            'to' => $target->value,
            'reason' => $reason,
            'applied' => ! $dryRun,
        ];

        $target === PrintJobStatus::Expired
            ? $counters['cloud_expired']++
            : $counters['cloud_needs_attention']++;

        if ($dryRun) {
            return;
        }

        $job->update([
            'status' => $target->value,
            // Never overwrite a real machine error with a bookkeeping note —
            // "paper_end" is more useful to the person holding the roll.
            'last_error' => $job->last_error ?? $reason,
            // `attempts` is DELIBERATELY absent. This sweep does not deliver,
            // so it has nothing to count (PR1).
        ]);
    }

    /**
     * The status this Cloud-owned row should end up in, and why.
     *
     * @return array{0: ?PrintJobStatus, 1: string}
     */
    private function targetFor(PrintJob $job, CarbonImmutable $now): array
    {
        $isMoney = $job->kind instanceof PrintJobKind && $job->kind->isMoneyDocument();

        if ($this->expiresAt($job)->lessThanOrEqualTo($now)) {
            // A money document past its TTL is NOT quietly discarded. Someone
            // may be waiting for that receipt; a person decides, and the
            // decision is recorded (T2.2 resolve).
            return $isMoney
                ? [PrintJobStatus::NeedsAttention, self::REASON_TTL_EXCEEDED_MONEY]
                : [PrintJobStatus::Expired, self::REASON_TTL_EXCEEDED];
        }

        $staleAfter = max(1, (int) config('print_jobs.reconcile.stale_delivering_seconds', 300));

        if ($job->status === PrintJobStatus::Delivering
            && $this->eventTime($job)->addSeconds($staleAfter)->lessThanOrEqualTo($now)) {
            // P-03 ACK-lost: "we sent it, nobody confirmed" is genuinely
            // neither printed nor failed, for a kitchen ticket as much as for
            // a receipt. Nothing is retried here either way.
            return [PrintJobStatus::NeedsAttention, self::REASON_ACK_LOST];
        }

        return [null, ''];
    }

    // =====================================================================
    //  Helpers
    // =====================================================================

    /**
     * TTL edge for a row. `expires_at` is stamped at ingest; when it is
     * missing (a row written before the column meant anything) the registry
     * recomputes it from the kind, so the TTL table stays the single source.
     */
    private function expiresAt(PrintJob $job): CarbonImmutable
    {
        if ($job->expires_at !== null) {
            return CarbonImmutable::instance($job->expires_at);
        }

        return $this->registry->expiresAt($job->kind, $this->eventTime($job));
    }

    /**
     * The row's REAL event time (P-07): when the paper came out, falling back
     * to when the row was written. Using `created_at` alone would date every
     * slip of an offline evening to the next morning's sync (#1091).
     */
    private function eventTime(PrintJob $job): CarbonImmutable
    {
        $at = $job->printed_reported_at ?? $job->created_at;

        return $at === null ? CarbonImmutable::now() : CarbonImmutable::instance($at);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function finding(PrintJob $job, string $code, array $context): array
    {
        return array_merge([
            'job_id' => $job->id,
            'branch_id' => $job->branch_id,
            'printer_id' => $job->printer_id,
            'transport' => $job->transport->value,
            'kind' => $job->kind->value,
            'status' => $job->status->value,
            'code' => $code,
        ], $context);
    }

    /** @return list<string> */
    private function branchIdsInScope(?string $branchFilter, ?string $transportFilter, CarbonImmutable $lookback): array
    {
        if ($branchFilter !== null) {
            return [$branchFilter];
        }

        return PrintJob::query()
            ->where('created_at', '>=', $lookback)
            ->when($transportFilter !== null, fn ($q) => $q->where('transport', $transportFilter))
            ->distinct()
            ->orderBy('branch_id')
            ->pluck('branch_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    private function nonEmpty(?string $value): ?string
    {
        return $value !== null && trim($value) !== '' ? trim($value) : null;
    }
}
