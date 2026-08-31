<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\PrintJob;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\PrintJobReconciler;
use App\Services\Printing\PrintJobRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

/**
 * plan-052 M2 / T2.1 — `print-jobs:reconcile`.
 *
 * The suite is organised around the two rules the sweep exists to obey:
 *
 *   §1b — Cloud NEVER schedules, retries or transitions a `ws_lan` row. The
 *         workstation owns that queue. Cloud looks and reports.
 *   PR1 — a money document is NEVER auto-retried, from any state, on any
 *         branch of the code.
 *
 * Both are tested as OBSERVABLE properties (a full row snapshot before/after),
 * not as "the current implementation happens not to do that" — the point is to
 * make the next refactor fail loudly if it starts doing it.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
});

/** A print_jobs row with everything the sweep reads under the test's control. */
function reconcileJob(array $overrides = [], ?string $branchId = null): PrintJob
{
    return PrintJob::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => $branchId ?? test()->branch->id,
        'transport' => PrintTransport::WsLan->value,
        'kind' => PrintJobKind::Kitchen->value,
        'status' => PrintJobStatus::Printed->value,
        'attempts' => 1,
        'printed_reported_at' => now()->subMinutes(2),
        'expires_at' => now()->addMinutes(13),
        'last_error' => null,
    ], $overrides));
}

/** Every column of every row, so "unchanged" means unchanged — including updated_at. */
function ledgerSnapshot(?string $branchId = null): array
{
    return DB::table('print_jobs')
        ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
        ->orderBy('id')
        ->get()
        ->map(fn ($row) => (array) $row)
        ->all();
}

function runReconcile(array $options = []): array
{
    return app(PrintJobReconciler::class)->reconcile($options);
}

function findingCodes(array $report): array
{
    return array_values(array_map(static fn (array $f): string => $f['code'], $report['findings']));
}

// =========================================================================
//  §1b — ws_lan rows are journal facts. Cloud looks. Cloud does not touch.
// =========================================================================

describe('§1b — Cloud never transitions a ws_lan row', function () {
    it('leaves every ws_lan row byte-identical, in every status', function () {
        foreach (PrintJobStatus::cases() as $status) {
            reconcileJob([
                'status' => $status->value,
                // Deliberately stale AND past TTL — the two conditions that
                // would make Cloud act if this were a Cloud-owned row.
                'printed_reported_at' => now()->subHours(6),
                'expires_at' => now()->subHours(5),
            ]);
        }

        $before = ledgerSnapshot();

        runReconcile();

        expect(ledgerSnapshot())->toEqual($before);
    });

    it('never bumps attempts on a ws_lan row (there is no retry to count)', function () {
        $job = reconcileJob([
            'status' => PrintJobStatus::NeedsAttention->value,
            'kind' => PrintJobKind::Kitchen->value,
            'attempts' => 3,
        ]);

        runReconcile();

        expect($job->fresh()->attempts)->toBe(3)
            ->and($job->fresh()->status)->toBe(PrintJobStatus::NeedsAttention);
    });

    it('reports a ws_lan needs_attention row instead of resolving it', function () {
        reconcileJob(['status' => PrintJobStatus::NeedsAttention->value]);

        $report = runReconcile();

        expect(findingCodes($report))->toContain('journal_needs_attention')
            ->and($report['changes'])->toBe([])
            ->and($report['branches'][0]['journal_needs_attention'])->toBe(1);
    });

    it('reports a ws_lan row stuck in delivering past the stale window', function () {
        config(['print_jobs.reconcile.stale_delivering_seconds' => 300]);

        reconcileJob([
            'status' => PrintJobStatus::Delivering->value,
            'printed_reported_at' => now()->subMinutes(10),
            'expires_at' => now()->addHour(),
        ]);

        $report = runReconcile();

        expect(findingCodes($report))->toContain('journal_stale_delivering')
            ->and($report['changes'])->toBe([]);
    });

    it('does NOT report a delivering row that is still inside the stale window', function () {
        config(['print_jobs.reconcile.stale_delivering_seconds' => 300]);

        reconcileJob([
            'status' => PrintJobStatus::Delivering->value,
            'printed_reported_at' => now()->subMinutes(2),
            'expires_at' => now()->addHour(),
        ]);

        expect(findingCodes(runReconcile()))->not->toContain('journal_stale_delivering');
    });

    it('reports a ws_lan job that is still open past its TTL — and expires nothing', function () {
        $job = reconcileJob([
            'status' => PrintJobStatus::Queued->value,
            'printed_reported_at' => null,
            'expires_at' => now()->subHour(),
        ]);

        $report = runReconcile();

        expect(findingCodes($report))->toContain('journal_past_ttl_open')
            ->and($job->fresh()->status)->toBe(PrintJobStatus::Queued);
    });

    it('reports a journal row that synced long after the paper came out (uplink, not printer)', function () {
        config(['print_jobs.reconcile.late_journal_seconds' => 3600]);

        // Printed at 20:00 while the shop was offline; the row landed at 09:00
        // the next morning. The printer was fine — the internet was not.
        reconcileJob([
            'status' => PrintJobStatus::Printed->value,
            'printed_reported_at' => now()->subHours(13),
            'created_at' => now(),
        ]);

        $report = runReconcile();
        $late = collect($report['findings'])->firstWhere('code', 'journal_late_sync');

        expect($late)->not->toBeNull()
            ->and($late['lag_seconds'])->toBeGreaterThanOrEqual(3600);
    });

    it('does not call a promptly-synced journal row late', function () {
        config(['print_jobs.reconcile.late_journal_seconds' => 3600]);

        reconcileJob([
            'printed_reported_at' => now()->subMinutes(2),
            'created_at' => now(),
        ]);

        expect(findingCodes(runReconcile()))->not->toContain('journal_late_sync');
    });

    it('reports an offline money-document reprint that carries no reason (printing.md §8)', function () {
        reconcileJob([
            'kind' => PrintJobKind::Receipt->value,
            'reprint_no' => 2,
            'reprint_reason' => null,
            'expires_at' => now()->addDay(),
        ]);

        expect(findingCodes(runReconcile()))->toContain('journal_money_reprint_no_reason');
    });

    it('does not report a reprint that DID carry a reason, nor a first print', function () {
        reconcileJob([
            'kind' => PrintJobKind::Receipt->value,
            'reprint_no' => 2,
            'reprint_reason' => 'customer asked for a second copy',
            'expires_at' => now()->addDay(),
        ]);
        reconcileJob([
            'kind' => PrintJobKind::Receipt->value,
            'reprint_no' => 1,
            'reprint_reason' => null,
            'expires_at' => now()->addDay(),
        ]);

        expect(findingCodes(runReconcile()))->not->toContain('journal_money_reprint_no_reason');
    });
});

// =========================================================================
//  Cloud-owned queue rows — the TTL table, and nothing else.
// =========================================================================

describe('cloudprnt — Cloud owns the queue, so Cloud runs the TTL table', function () {
    it('expires a non-money job past its TTL', function () {
        $job = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'kind' => PrintJobKind::Kitchen->value,
            'status' => PrintJobStatus::Queued->value,
            'printed_reported_at' => null,
            'expires_at' => now()->subSecond(),
        ]);

        $report = runReconcile();

        expect($job->fresh()->status)->toBe(PrintJobStatus::Expired)
            ->and($job->fresh()->last_error)->toBe(PrintJobReconciler::REASON_TTL_EXCEEDED)
            ->and($report['branches'][0]['cloud_expired'])->toBe(1);
    });

    it('expires at the TTL edge and NOT one second before it', function () {
        $atEdge = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'printed_reported_at' => null,
            'expires_at' => now(),
        ]);

        $justInside = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'printed_reported_at' => null,
            'expires_at' => now()->addSecond(),
        ]);

        runReconcile();

        expect($atEdge->fresh()->status)->toBe(PrintJobStatus::Expired)
            ->and($justInside->fresh()->status)->toBe(PrintJobStatus::Queued);
    });

    it('honours the per-kind TTL from the registry when expires_at was never stamped', function () {
        // kitchen = 15 min, report = 2h (config/print_jobs.php).
        $kitchen = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'kind' => PrintJobKind::Kitchen->value,
            'status' => PrintJobStatus::Queued->value,
            'printed_reported_at' => now()->subMinutes(20),
            'expires_at' => null,
        ]);

        $report = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'kind' => PrintJobKind::Report->value,
            'status' => PrintJobStatus::Queued->value,
            'printed_reported_at' => now()->subMinutes(20),
            'expires_at' => null,
        ]);

        runReconcile();

        expect($kitchen->fresh()->status)->toBe(PrintJobStatus::Expired)
            ->and($report->fresh()->status)->toBe(PrintJobStatus::Queued);
    });

    it('leaves terminal rows alone — a printed receipt does not expire tomorrow', function () {
        $rows = [];

        foreach ([PrintJobStatus::Printed, PrintJobStatus::Failed, PrintJobStatus::Expired] as $status) {
            $rows[] = reconcileJob([
                'transport' => PrintTransport::CloudPrnt->value,
                'status' => $status->value,
                'expires_at' => now()->subDay(),
            ]);
        }

        $report = runReconcile();

        foreach ($rows as $i => $row) {
            expect($row->fresh()->status->value)
                ->toBe([PrintJobStatus::Printed, PrintJobStatus::Failed, PrintJobStatus::Expired][$i]->value);
        }

        expect($report['changes'])->toBe([]);
    });

    it('flags an ACK-lost delivering row as needs_attention (P-03) without retrying it', function () {
        config(['print_jobs.reconcile.stale_delivering_seconds' => 300]);

        $job = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'kind' => PrintJobKind::Kitchen->value,
            'status' => PrintJobStatus::Delivering->value,
            'attempts' => 2,
            'printed_reported_at' => now()->subMinutes(10),
            'expires_at' => now()->addHour(),
        ]);

        runReconcile();

        expect($job->fresh()->status)->toBe(PrintJobStatus::NeedsAttention)
            ->and($job->fresh()->last_error)->toBe(PrintJobReconciler::REASON_ACK_LOST)
            // attempts is the delivery counter; the sweep delivers nothing.
            ->and($job->fresh()->attempts)->toBe(2);
    });

    it('never overwrites a real machine error with its own bookkeeping note', function () {
        $job = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'last_error' => 'paper_end',
            'expires_at' => now()->subMinute(),
        ]);

        runReconcile();

        expect($job->fresh()->last_error)->toBe('paper_end');
    });
});

// =========================================================================
//  PR1 — money documents are never auto-retried, from anywhere.
// =========================================================================

describe('PR1 — a money document is never re-sent by the sweep', function () {
    it('parks an expired money document in needs_attention instead of discarding it', function () {
        foreach ([PrintJobKind::Receipt, PrintJobKind::RedInvoice, PrintJobKind::DebtSlip] as $kind) {
            $job = reconcileJob([
                'transport' => PrintTransport::CloudPrnt->value,
                'kind' => $kind->value,
                'status' => PrintJobStatus::Queued->value,
                'attempts' => 1,
                'printed_reported_at' => null,
                'expires_at' => now()->subMinute(),
            ]);

            runReconcile();

            expect($job->fresh()->status)->toBe(PrintJobStatus::NeedsAttention)
                ->and($job->fresh()->last_error)->toBe(PrintJobReconciler::REASON_TTL_EXCEEDED_MONEY)
                ->and($job->fresh()->attempts)->toBe(1);
        }
    });

    it('leaves a money document already in needs_attention exactly where it is', function () {
        $job = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'kind' => PrintJobKind::Receipt->value,
            'status' => PrintJobStatus::NeedsAttention->value,
            'attempts' => 1,
            'expires_at' => now()->subDay(),
        ]);

        $before = $job->fresh()->updated_at;

        $report = runReconcile();

        expect($job->fresh()->status)->toBe(PrintJobStatus::NeedsAttention)
            ->and($job->fresh()->attempts)->toBe(1)
            ->and($job->fresh()->updated_at->timestamp)->toBe($before->timestamp)
            ->and($report['changes'])->toBe([]);
    });

    it('never moves ANY job back into queued or delivering', function () {
        foreach (PrintJobKind::cases() as $kind) {
            foreach ([PrintJobStatus::NeedsAttention, PrintJobStatus::Failed, PrintJobStatus::Queued, PrintJobStatus::Delivering] as $status) {
                reconcileJob([
                    'transport' => PrintTransport::CloudPrnt->value,
                    'kind' => $kind->value,
                    'status' => $status->value,
                    'printed_reported_at' => now()->subDay(),
                    'expires_at' => now()->subDay(),
                ]);
            }
        }

        $report = runReconcile();

        $targets = array_values(array_unique(array_map(static fn (array $c): string => $c['to'], $report['changes'])));

        expect($targets)->not->toContain('queued')
            ->and($targets)->not->toContain('delivering')
            ->and($targets)->not->toContain('printed');
    });
});

// =========================================================================
//  Idempotency, dry-run, scoping, resilience
// =========================================================================

describe('the sweep is safe to run again, and again', function () {
    it('changes nothing on a second consecutive run (full-ledger snapshot)', function () {
        config(['print_jobs.reconcile.stale_delivering_seconds' => 300]);

        // A mix that exercises every branch of the decision table.
        reconcileJob(['status' => PrintJobStatus::NeedsAttention->value]);
        reconcileJob(['status' => PrintJobStatus::Delivering->value, 'printed_reported_at' => now()->subHour()]);
        reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ]);
        reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'kind' => PrintJobKind::Receipt->value,
            'status' => PrintJobStatus::Delivering->value,
            'printed_reported_at' => now()->subHour(),
            'expires_at' => now()->addHour(),
        ]);

        runReconcile();
        $afterFirst = ledgerSnapshot();

        $secondReport = runReconcile();

        expect(ledgerSnapshot())->toEqual($afterFirst)
            ->and($secondReport['changes'])->toBe([]);
    });

    it('writes NOTHING in --dry-run but still reports what it would do', function () {
        reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ]);

        $before = ledgerSnapshot();

        $report = runReconcile(['dry_run' => true]);

        expect(ledgerSnapshot())->toEqual($before)
            ->and($report['changes'])->toHaveCount(1)
            ->and($report['changes'][0]['to'])->toBe('expired')
            ->and($report['changes'][0]['applied'])->toBeFalse();
    });
});

describe('scoping', function () {
    it('--branch touches only that branch', function () {
        $mine = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ]);

        $theirs = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ], test()->otherBranch->id);

        runReconcile(['branch_id' => test()->branch->id]);

        expect($mine->fresh()->status)->toBe(PrintJobStatus::Expired)
            ->and($theirs->fresh()->status)->toBe(PrintJobStatus::Queued);
    });

    it('--transport touches only that transport', function () {
        $cloud = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ]);

        reconcileJob([
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ]);

        $report = runReconcile(['transport' => PrintTransport::CloudPrnt->value]);

        expect($cloud->fresh()->status)->toBe(PrintJobStatus::Expired)
            // The ws_lan row was not even scanned, so it produced no finding.
            ->and(findingCodes($report))->toBe([]);
    });

    it('sweeps several branches in one run', function () {
        reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ]);
        reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ], test()->otherBranch->id);

        $report = runReconcile();

        expect($report['branches'])->toHaveCount(2)
            ->and($report['changes'])->toHaveCount(2);
    });

    it('handles a branch with no jobs at all without complaining', function () {
        $report = runReconcile(['branch_id' => test()->otherBranch->id]);

        expect($report['scanned'])->toBe(0)
            ->and($report['changes'])->toBe([])
            ->and($report['findings'])->toBe([])
            ->and($report['errors'])->toBe([]);
    });

    it('ignores rows older than the lookback window', function () {
        config(['print_jobs.reconcile.lookback_hours' => 24]);

        reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subDays(3),
            'created_at' => now()->subDays(3),
        ]);

        expect(runReconcile()['scanned'])->toBe(0);
    });
});

describe('resilience', function () {
    it('keeps sweeping the other branches when one branch blows up', function () {
        // Poison ONE branch: a label job with no expires_at forces the sweep
        // through the registry, which this stub refuses for that kind only.
        reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'kind' => PrintJobKind::Label->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => null,
        ]);

        $healthy = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'kind' => PrintJobKind::Kitchen->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ], test()->otherBranch->id);

        app()->instance(PrintJobRegistry::class, new class extends PrintJobRegistry
        {
            public function expiresAt(PrintJobKind|string $kind, DateTimeInterface $issuedAt): CarbonImmutable
            {
                if (($kind instanceof PrintJobKind ? $kind->value : $kind) === 'label') {
                    throw new RuntimeException('poisoned branch');
                }

                return parent::expiresAt($kind, $issuedAt);
            }
        });

        $report = app(PrintJobReconciler::class)->reconcile([]);

        expect($report['errors'])->toHaveCount(1)
            ->and($report['errors'][0]['branch_id'])->toBe(test()->branch->id)
            // …and the healthy branch was still reconciled.
            ->and($healthy->fresh()->status)->toBe(PrintJobStatus::Expired);
    });
});

// =========================================================================
//  The command wrapper
// =========================================================================

describe('print-jobs:reconcile (console)', function () {
    it('runs clean on an empty ledger', function () {
        $this->artisan('print-jobs:reconcile')->assertSuccessful();
    });

    it('rejects an unknown transport instead of silently sweeping everything', function () {
        reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('print-jobs:reconcile --transport=carrier-pigeon')
            ->assertExitCode(Command::INVALID);
    });

    it('stamps a heartbeat so a sweep that stopped running is distinguishable from a quiet shop', function () {
        Cache::forget('printing.reconcile.last_run_at');

        $this->artisan('print-jobs:reconcile --no-alerts')->assertSuccessful();

        expect(Cache::get('printing.reconcile.last_run_at'))->not->toBeNull();
    });

    it('does not stamp the heartbeat on a dry run — a preview is not a run', function () {
        Cache::forget('printing.reconcile.last_run_at');

        $this->artisan('print-jobs:reconcile --dry-run')->assertSuccessful();

        expect(Cache::get('printing.reconcile.last_run_at'))->toBeNull();
    });

    it('applies the TTL table end to end through the command', function () {
        $job = reconcileJob([
            'transport' => PrintTransport::CloudPrnt->value,
            'status' => PrintJobStatus::Queued->value,
            'expires_at' => now()->subMinute(),
        ]);

        $this->artisan('print-jobs:reconcile --no-alerts')->assertSuccessful();

        expect($job->fresh()->status)->toBe(PrintJobStatus::Expired);
    });
});
