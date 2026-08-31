<?php

namespace App\Services\Printing;

use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use Carbon\CarbonImmutable;

/**
 * plan-052 M2 / T2.3 — "how much open print work is piling up, and since when",
 * plus "which machines have gone quiet".
 *
 * Two deliberate design choices:
 *
 * **1. Age is a DURATION, not a calendar subtraction.** A bucket says "this
 * job has been waiting more than two days", which is the same sentence in
 * Tokyo and in Hanoi. Turning it into business dates would make one shop's
 * report depend on another shop's midnight (#1091), and buy nothing: nobody
 * asks "which business day is this backlog on", they ask "how long has it been
 * stuck".
 *
 * **2. Cloud never probes a ws_lan printer (P-38).** The workstation owns
 * those machines and probes them itself; all Cloud has is the journal, so
 * Cloud INFERS silence — `last_seen_at` stopped moving. For cloudprnt the
 * machine sits behind the shop's NAT and cannot be dialled at all, so silence
 * is again the only signal (`poll_silence`). This class therefore opens no
 * socket and makes no request, and an arch test keeps it that way.
 */
class PrintJobAgingService
{
    /**
     * Open print work at a branch, bucketed by age and broken down by status
     * and by printer.
     *
     * @return array{
     *     branch_id: string,
     *     generated_at: string,
     *     total: int,
     *     buckets: array<string, int>,
     *     by_status: array<string, array<string, int>>,
     *     by_printer: list<array<string, mixed>>,
     *     needs_attention: int,
     *     money_document_open: int,
     * }
     */
    public function branchAging(string $branchId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $labels = $this->bucketLabels();
        $statuses = $this->trackedStatuses();

        $buckets = array_fill_keys($labels, 0);
        $byStatus = [];
        $byPrinter = [];
        $total = 0;
        $needsAttention = 0;
        $moneyOpen = 0;

        PrintJob::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', $statuses)
            ->chunkById(500, function ($chunk) use (
                &$buckets, &$byStatus, &$byPrinter, &$total, &$needsAttention, &$moneyOpen, $now, $labels
            ): void {
                foreach ($chunk as $job) {
                    $total++;

                    $label = $this->bucketFor($this->ageDays($job, $now));
                    $buckets[$label]++;

                    $status = $job->status->value;
                    $byStatus[$status] ??= array_fill_keys($labels, 0);
                    $byStatus[$status][$label]++;

                    $printerKey = $job->printer_id ?? '(unassigned)';
                    $byPrinter[$printerKey] ??= [
                        'printer_id' => $job->printer_id,
                        'total' => 0,
                        'oldest_age_days' => 0.0,
                        'needs_attention' => 0,
                    ];
                    $byPrinter[$printerKey]['total']++;
                    $byPrinter[$printerKey]['oldest_age_days'] = max(
                        $byPrinter[$printerKey]['oldest_age_days'],
                        round($this->ageDays($job, $now), 3),
                    );

                    if ($job->status === PrintJobStatus::NeedsAttention) {
                        $needsAttention++;
                        $byPrinter[$printerKey]['needs_attention']++;
                    }

                    if ($job->kind instanceof PrintJobKind && $job->kind->isMoneyDocument()) {
                        $moneyOpen++;
                    }
                }
            });

        ksort($byStatus);

        return [
            'branch_id' => $branchId,
            'generated_at' => $now->toIso8601String(),
            'total' => $total,
            'buckets' => $buckets,
            'by_status' => $byStatus,
            'by_printer' => array_values($byPrinter),
            'needs_attention' => $needsAttention,
            'money_document_open' => $moneyOpen,
        ];
    }

    /**
     * Printers that have stopped speaking.
     *
     * A printer that has NEVER been seen is not silent — it is new. Alerting on
     * one is how a shop learns to ignore the alert channel the same afternoon
     * it configures its machines.
     *
     * @return list<array{
     *     printer_id: string, printer_name: string, transport: string,
     *     detection: string, last_seen_at: ?string, silent_for_seconds: int,
     *     threshold_seconds: int, last_status: ?string,
     * }>
     */
    public function silentPrinters(string $branchId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $floorSeconds = max(1, (int) config('print_jobs.alerts.printer_silence_minutes', 60)) * 60;

        $silent = [];

        foreach (Printer::query()->where('branch_id', $branchId)->where('is_active', true)->get() as $printer) {
            if ($printer->last_seen_at === null) {
                continue;
            }

            $profile = $printer->capabilityProfile();
            $transport = $printer->transport ?? PrintTransport::WsLan;

            // The profile may declare a slower rhythm than the global floor
            // (a machine polled every 5 minutes with 3 allowed misses is not
            // late at minute 10). The longer of the two wins — never the
            // shorter, or a correctly-configured slow machine alerts forever.
            $threshold = max(
                $floorSeconds,
                $profile->healthIntervalSeconds() * $profile->offlineAfterMisses(),
            );

            $silentFor = (int) CarbonImmutable::instance($printer->last_seen_at)->diffInSeconds($now, false);

            if ($silentFor < $threshold) {
                continue;
            }

            $silent[] = [
                'printer_id' => (string) $printer->id,
                'printer_name' => (string) $printer->name,
                'transport' => $transport->value,
                'detection' => $this->detectionFor($transport, $profile),
                'last_seen_at' => CarbonImmutable::instance($printer->last_seen_at)->toIso8601String(),
                'silent_for_seconds' => $silentFor,
                'threshold_seconds' => $threshold,
                'last_status' => $printer->last_status?->value,
            ];
        }

        return $silent;
    }

    /**
     * P-38 — how Cloud came to believe a machine is quiet. `journal_silence`
     * means "the workstation stopped reporting prints from it"; `poll_silence`
     * means "the printer stopped polling us". Neither is a probe, and the
     * distinction matters when someone asks how sure we are.
     */
    private function detectionFor(PrintTransport $transport, PrinterCapabilityProfile $profile): string
    {
        if ($transport->isJournalMode()) {
            return 'journal_silence';
        }

        return $profile->healthMethod() === 'poll_silence' ? 'poll_silence' : 'ack_silence';
    }

    /** @return list<string> */
    public function bucketLabels(): array
    {
        $labels = [];
        $previous = 0;

        foreach ($this->bucketEdges() as $edge) {
            $labels[] = $previous === 0 ? "<{$edge}d" : "{$previous}-{$edge}d";
            $previous = $edge;
        }

        $labels[] = "{$previous}d+";

        return $labels;
    }

    /** @return list<int> */
    private function bucketEdges(): array
    {
        /** @var list<int> $edges */
        $edges = config('print_jobs.aging.buckets_days', [1, 2, 7]);

        $edges = array_values(array_unique(array_filter(array_map('intval', $edges), static fn (int $d): bool => $d > 0)));
        sort($edges);

        return $edges === [] ? [1] : $edges;
    }

    /** @return list<string> */
    private function trackedStatuses(): array
    {
        /** @var list<string> $statuses */
        $statuses = config('print_jobs.aging.statuses', ['queued', 'delivering', 'needs_attention', 'failed']);

        $valid = array_values(array_filter(
            array_map('strval', $statuses),
            static fn (string $s): bool => PrintJobStatus::tryFrom($s) !== null,
        ));

        return $valid === [] ? [PrintJobStatus::NeedsAttention->value] : $valid;
    }

    private function bucketFor(float $ageDays): string
    {
        $previous = 0;

        foreach ($this->bucketEdges() as $edge) {
            // Half-open [previous, edge): a job aged EXACTLY 1.0 day belongs to
            // the 1-2d bucket, not to <1d. Boundaries have to fall one way and
            // stay there, or a report changes shape as the clock ticks past a
            // round number.
            if ($ageDays < $edge) {
                return $previous === 0 ? "<{$edge}d" : "{$previous}-{$edge}d";
            }
            $previous = $edge;
        }

        return "{$previous}d+";
    }

    /**
     * Age from the job's REAL event time (P-07), so a slip printed offline
     * last night is one night old, not zero seconds old because its row landed
     * at breakfast.
     */
    private function ageDays(PrintJob $job, CarbonImmutable $now): float
    {
        $at = $job->printed_reported_at ?? $job->created_at;

        if ($at === null) {
            return 0.0;
        }

        return max(0.0, CarbonImmutable::instance($at)->diffInSeconds($now, false) / 86400);
    }
}
