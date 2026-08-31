<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\PrintJobAgingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * plan-052 M2 / T2.3 — the aging report and the silent-printer detector.
 *
 * The two claims under test:
 *
 *   - **Age is a duration, so the report is timezone-invariant** (#1091). A
 *     job "stuck more than two days" is stuck more than two days in Tokyo and
 *     in Hanoi. This is asserted directly: identical jobs at three branch
 *     timezones must produce byte-identical buckets.
 *   - **Cloud never probes a shop printer** (P-38). Silence is INFERRED — from
 *     the journal for ws_lan, from missed polls for cloudprnt. The service
 *     opens no socket, and an arch check keeps it that way.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
        'is_active' => true,
    ]);
});

function agingJob(array $overrides = [], ?string $branchId = null): PrintJob
{
    return PrintJob::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => $branchId ?? test()->branch->id,
        'transport' => PrintTransport::WsLan->value,
        'kind' => PrintJobKind::Kitchen->value,
        'status' => PrintJobStatus::NeedsAttention->value,
    ], $overrides));
}

function aging(?string $branchId = null, ?CarbonImmutable $now = null): array
{
    return app(PrintJobAgingService::class)->branchAging($branchId ?? test()->branch->id, $now);
}

describe('bucketing', function () {
    it('drops each job into the right bucket', function () {
        config(['print_jobs.aging.buckets_days' => [1, 2, 7]]);

        agingJob(['printed_reported_at' => now()->subHours(3)]);   // <1d
        agingJob(['printed_reported_at' => now()->subHours(30)]);  // 1-2d
        agingJob(['printed_reported_at' => now()->subDays(4)]);    // 2-7d
        agingJob(['printed_reported_at' => now()->subDays(30)]);   // 7d+

        expect(aging()['buckets'])->toBe([
            '<1d' => 1,
            '1-2d' => 1,
            '2-7d' => 1,
            '7d+' => 1,
        ]);
    });

    it('puts an exact-24h job in the 1-2d bucket, and 24h-minus-a-second in <1d', function () {
        config(['print_jobs.aging.buckets_days' => [1, 2, 7]]);

        // Boundaries have to fall one way and STAY there — a report whose
        // shape changes as the clock ticks past a round number is a report
        // nobody can compare with yesterday's.
        agingJob(['printed_reported_at' => now()->subDay()]);
        agingJob(['printed_reported_at' => now()->subDay()->addSecond()]);

        $buckets = aging()['buckets'];

        expect($buckets['<1d'])->toBe(1)
            ->and($buckets['1-2d'])->toBe(1);
    });

    it('puts an exact-48h job in the 2-7d bucket', function () {
        config(['print_jobs.aging.buckets_days' => [1, 2, 7]]);

        agingJob(['printed_reported_at' => now()->subDays(2)]);
        agingJob(['printed_reported_at' => now()->subDays(2)->addSecond()]);

        expect(aging()['buckets']['2-7d'])->toBe(1)
            ->and(aging()['buckets']['1-2d'])->toBe(1);
    });

    it('ages a job from the paper, not from the sync (P-07)', function () {
        config(['print_jobs.aging.buckets_days' => [1, 2, 7]]);

        // Printed three days ago at the shop; the row landed a minute ago.
        agingJob([
            'printed_reported_at' => now()->subDays(3),
            'created_at' => now()->subMinute(),
        ]);

        expect(aging()['buckets']['2-7d'])->toBe(1)
            ->and(aging()['buckets']['<1d'])->toBe(0);
    });

    it('counts only the configured OPEN statuses', function () {
        config(['print_jobs.aging.statuses' => ['needs_attention', 'failed']]);

        agingJob(['status' => PrintJobStatus::NeedsAttention->value]);
        agingJob(['status' => PrintJobStatus::Failed->value]);
        agingJob(['status' => PrintJobStatus::Printed->value]);
        agingJob(['status' => PrintJobStatus::Expired->value]);

        expect(aging()['total'])->toBe(2);
    });

    it('breaks the counts down by status and by printer', function () {
        $printer = Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
        ]);

        agingJob(['status' => PrintJobStatus::NeedsAttention->value, 'printer_id' => $printer->id]);
        agingJob(['status' => PrintJobStatus::Failed->value, 'printer_id' => $printer->id]);
        agingJob(['status' => PrintJobStatus::Failed->value, 'printer_id' => null]);

        $report = aging();

        expect($report['by_status'])->toHaveKeys(['failed', 'needs_attention'])
            ->and($report['by_printer'])->toHaveCount(2)
            ->and(collect($report['by_printer'])->firstWhere('printer_id', $printer->id)['total'])->toBe(2)
            ->and($report['needs_attention'])->toBe(1);
    });

    it('counts open money documents separately — they are the expensive ones', function () {
        agingJob(['kind' => PrintJobKind::Receipt->value]);
        agingJob(['kind' => PrintJobKind::Kitchen->value]);

        expect(aging()['money_document_open'])->toBe(1);
    });

    it('returns an empty, well-formed report for a branch with nothing open', function () {
        $report = aging();

        expect($report['total'])->toBe(0)
            ->and($report['by_printer'])->toBe([])
            ->and(array_sum($report['buckets']))->toBe(0);
    });

    it('never counts another branch', function () {
        $other = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
        ]);

        agingJob();
        agingJob([], $other->id);

        expect(aging()['total'])->toBe(1)
            ->and(aging($other->id)['total'])->toBe(1);
    });
});

describe('#1091 — the report reads the same from every timezone', function () {
    it('produces identical buckets for identical jobs at three branch timezones', function () {
        config(['print_jobs.aging.buckets_days' => [1, 2, 7]]);

        $now = CarbonImmutable::parse('2026-07-28T15:00:00Z');
        $this->travelTo($now);

        $reports = [];

        foreach (['Asia/Tokyo', 'Asia/Ho_Chi_Minh', 'UTC'] as $tz) {
            $branch = Branch::factory()->create([
                'console_organization_id' => $this->orgId,
                'console_brand_id' => $this->brand->console_brand_id,
                'timezone' => $tz,
                'is_active' => true,
            ]);

            // The same instants everywhere — only the branch clock differs.
            foreach ([3, 30, 96, 800] as $hoursAgo) {
                agingJob(['printed_reported_at' => $now->subHours($hoursAgo)], $branch->id);
            }

            $reports[$tz] = aging($branch->id, $now)['buckets'];
        }

        expect($reports['Asia/Ho_Chi_Minh'])->toBe($reports['Asia/Tokyo'])
            ->and($reports['UTC'])->toBe($reports['Asia/Tokyo'])
            ->and($reports['Asia/Tokyo'])->toBe(['<1d' => 1, '1-2d' => 1, '2-7d' => 1, '7d+' => 1]);

        $this->travelBack();
    });
});

describe('silent printers (P-38 — inferred, never probed)', function () {
    it('flags a ws_lan printer whose journal went quiet, labelled journal_silence', function () {
        config(['print_jobs.alerts.printer_silence_minutes' => 60]);

        $printer = Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'transport' => PrintTransport::WsLan->value,
            'last_seen_at' => now()->subHours(3),
            'is_active' => true,
        ]);

        $silent = app(PrintJobAgingService::class)->silentPrinters($this->branch->id);

        expect($silent)->toHaveCount(1)
            ->and($silent[0]['printer_id'])->toBe($printer->id)
            ->and($silent[0]['detection'])->toBe('journal_silence');
    });

    it('flags a cloudprnt printer that stopped polling, labelled poll_silence', function () {
        config(['print_jobs.alerts.printer_silence_minutes' => 60]);

        Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'transport' => PrintTransport::CloudPrnt->value,
            'model_profile' => ['health' => ['method' => 'poll_silence', 'interval_s' => 60, 'offline_after_misses' => 3]],
            'last_seen_at' => now()->subHours(3),
            'is_active' => true,
        ]);

        $silent = app(PrintJobAgingService::class)->silentPrinters($this->branch->id);

        expect($silent)->toHaveCount(1)
            ->and($silent[0]['detection'])->toBe('poll_silence');
    });

    it('stays quiet about a printer that spoke recently', function () {
        config(['print_jobs.alerts.printer_silence_minutes' => 60]);

        Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'last_seen_at' => now()->subMinutes(10),
            'is_active' => true,
        ]);

        expect(app(PrintJobAgingService::class)->silentPrinters($this->branch->id))->toBe([]);
    });

    it('never calls a brand-new printer silent', function () {
        // A machine nobody has used yet is not broken. Alerting on it is how a
        // shop learns to ignore the channel the afternoon it sets up.
        Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'last_seen_at' => null,
            'is_active' => true,
        ]);

        expect(app(PrintJobAgingService::class)->silentPrinters($this->branch->id))->toBe([]);
    });

    it('ignores a deactivated printer', function () {
        config(['print_jobs.alerts.printer_silence_minutes' => 60]);

        Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'last_seen_at' => now()->subDays(30),
            'is_active' => false,
        ]);

        expect(app(PrintJobAgingService::class)->silentPrinters($this->branch->id))->toBe([]);
    });

    it('respects a profile that declares a slower rhythm than the global floor', function () {
        config(['print_jobs.alerts.printer_silence_minutes' => 5]);

        // 10 min interval × 6 allowed misses = 60 min before this machine is
        // late. It has been quiet 20 minutes: on schedule, not missing.
        Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'model_profile' => ['health' => ['method' => 'tcp_dial', 'interval_s' => 600, 'offline_after_misses' => 6]],
            'last_seen_at' => now()->subMinutes(20),
            'is_active' => true,
        ]);

        expect(app(PrintJobAgingService::class)->silentPrinters($this->branch->id))->toBe([]);
    });

    it('never scopes across branches', function () {
        config(['print_jobs.alerts.printer_silence_minutes' => 60]);

        $other = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
        ]);

        Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $other->id,
            'last_seen_at' => now()->subDays(2),
            'is_active' => true,
        ]);

        expect(app(PrintJobAgingService::class)->silentPrinters($this->branch->id))->toBe([]);
    });

    it('[arch] opens no socket and makes no request — Cloud infers, it does not probe', function () {
        $source = file_get_contents(app_path('Services/Printing/PrintJobAgingService.php'));

        foreach (['Http::', 'fsockopen', 'curl_', 'stream_socket_client', 'file_get_contents(\'http'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    });
});
