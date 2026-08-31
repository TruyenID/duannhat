<?php

declare(strict_types=1);

/**
 * #2555 — `audit_logs` retention.
 *
 * The table had no prune job of any kind, so the interesting assertions are not
 * "old rows go away" (any DELETE does that) but the four ways a retention sweep
 * destroys evidence it was supposed to keep:
 *
 *   - it deletes something INSIDE the window;
 *   - `--dry-run` deletes anyway;
 *   - a mistyped retention env silently shortens the window below the PCI floor;
 *   - the chunk loop never terminates, or terminates early and calls it drained.
 *
 * Retention window and the PCI reasoning live in `config/audit.php`.
 */

use App\Models\AuditLog;
use Database\Factories\AuditLogFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

uses()->group('audit');

/**
 * Create an audit row whose `created_at` sits N days in the past.
 *
 * `AuditLog` does not use `HasFactory` (the model is deliberately thin), so the
 * factory is instantiated directly rather than via `AuditLog::factory()`.
 */
function auditRowAgedDays(int $days, string $action = 'test.action'): AuditLog
{
    return AuditLogFactory::new()->create([
        'action' => $action,
        'created_at' => Carbon::now('UTC')->subDays($days),
        'updated_at' => Carbon::now('UTC')->subDays($days),
    ]);
}

it('deletes rows older than the retention cutoff', function () {
    $retention = (int) config('audit.retention_days');

    $stale = auditRowAgedDays($retention + 1, 'stale.row');
    auditRowAgedDays($retention + 400, 'ancient.row');

    $this->artisan('audit:prune')->assertSuccessful();

    expect(AuditLog::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('keeps every row inside the retention window', function () {
    $retention = (int) config('audit.retention_days');

    // The load-bearing direction: a prune that over-deletes destroys the exact
    // evidence PCI Req 10.5.1 requires to be kept, and nothing would notice.
    $keep = collect([0, 1, 90, 364, $retention - 1])
        ->map(fn (int $days) => auditRowAgedDays($days, "keep.{$days}"))
        ->all();

    auditRowAgedDays($retention + 5, 'drop.me');

    $this->artisan('audit:prune')->assertSuccessful();

    foreach ($keep as $row) {
        expect(AuditLog::query()->whereKey($row->id)->exists())
            ->toBeTrue("row aged inside the {$retention}d window was deleted: {$row->action}");
    }

    expect(AuditLog::query()->count())->toBe(count($keep));
});

it('deletes nothing on a dry run and reports the eligible count', function () {
    $retention = (int) config('audit.retention_days');

    auditRowAgedDays($retention + 10);
    auditRowAgedDays($retention + 20);
    auditRowAgedDays(5);

    $this->artisan('audit:prune --dry-run')
        ->expectsOutputToContain('dry-run: 2 row(s)')
        ->assertSuccessful();

    expect(AuditLog::query()->count())->toBe(3);
});

it('reports the table size before and after when asked to measure', function () {
    config()->set('audit.prune.pause_ms', 0);

    $retention = (int) config('audit.retention_days');

    auditRowAgedDays($retention + 10);
    auditRowAgedDays($retention + 11);
    auditRowAgedDays(3);

    // The issue asks for the table measured before/after. It is opt-in because
    // COUNT(*) on this table is a full scan.
    $this->artisan('audit:prune --measure')
        ->expectsOutputToContain('table rows: 3 before, 1 after')
        ->assertSuccessful();
});

it('drains a backlog larger than one chunk without an unbounded delete', function () {
    config()->set('audit.prune.chunk_size', 2);
    config()->set('audit.prune.pause_ms', 0);

    $retention = (int) config('audit.retention_days');

    for ($i = 1; $i <= 7; $i++) {
        auditRowAgedDays($retention + $i);
    }
    auditRowAgedDays(1);

    $this->artisan('audit:prune')
        ->expectsOutputToContain('deleted 7 row(s) in 4 batch(es)')
        ->assertSuccessful();

    expect(AuditLog::query()->count())->toBe(1);
});

it('stops at the max-rows ceiling and leaves the rest for the next run', function () {
    config()->set('audit.prune.chunk_size', 2);
    config()->set('audit.prune.pause_ms', 0);

    $retention = (int) config('audit.retention_days');

    for ($i = 1; $i <= 7; $i++) {
        auditRowAgedDays($retention + $i);
    }

    $this->artisan('audit:prune --max-rows=4')
        ->expectsOutputToContain('stopped: max-rows')
        ->assertSuccessful();

    expect(AuditLog::query()->count())->toBe(3);
});

it('refuses a retention window below the PCI floor and deletes nothing', function () {
    $floor = (int) config('audit.pci_floor_days');

    auditRowAgedDays(1000);

    $this->artisan('audit:prune --days=30')
        ->expectsOutputToContain("below the PCI DSS v4.0 Req 10.5.1 floor of {$floor} days")
        ->assertFailed();

    expect(AuditLog::query()->count())->toBe(1);

    // Same guard when the shortened window arrives through config rather than
    // the flag — an env var is the likelier way this goes wrong in production.
    config()->set('audit.retention_days', 30);

    $this->artisan('audit:prune')->assertFailed();

    expect(AuditLog::query()->count())->toBe(1);
});

it('is scheduled nightly, off-peak, on the operations timezone', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'audit:prune'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect((string) $event->expression)->toBe('50 2 * * *')
        ->and((string) ($event->timezone ?? config('app.timezone')))->toBe((string) config('app.operations_timezone'));
});
