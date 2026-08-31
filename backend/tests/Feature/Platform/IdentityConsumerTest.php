<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\IdentityInboxEntry;
use App\Models\Organization;
use App\Services\Platform\Contracts\IdentityEventSource;
use App\Services\Platform\IdentityEventConsumer;
use App\Services\Platform\IdentitySourceManager;
use App\Services\Platform\Source\NullEventSource;
use App\Services\Platform\Source\SqsEventSource;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

/**
 * #3199 (ADR 0002) — the receiving end.
 */
function envelope(array $overrides = []): array
{
    return [
        'specversion' => '1.0',
        'id' => (string) Str::uuid(),
        'type' => 'jp.godx.identity.organization.updated',
        'subject' => 'organization/org-remote-1',
        'sequence' => 10,
        'data' => ['id' => 'org-remote-1', 'name' => 'Renamed', 'slug' => 'betoya'],
        ...$overrides,
    ];
}

/** A source the test drives; records what was acknowledged. */
function fakeSource(array $envelopes, bool $ready = true, ?array &$acked = null): IdentityEventSource
{
    $acked ??= [];

    return new class($envelopes, $ready, $acked) implements IdentityEventSource
    {
        public function __construct(private array $envelopes, private bool $ready, private array &$acked) {}

        public function receive(int $max): array
        {
            return array_map(
                fn (array $e, int $i): array => ['receipt' => 'r'.$i, 'envelope' => $e],
                $this->envelopes,
                array_keys($this->envelopes),
            );
        }

        public function acknowledge(mixed $receipt): void
        {
            $this->acked[] = $receipt;
        }

        public function isReady(): bool
        {
            return $this->ready;
        }

        public function describe(): string
        {
            return 'fake';
        }
    };
}

beforeEach(function (): void {
    $this->organization = Organization::factory()->create([
        'console_organization_id' => 'org-remote-1',
        'name' => 'Betoya',
        'slug' => 'betoya',
    ]);
});

it('refuses the run when the source is not configured, and acknowledges nothing', function (): void {
    $acked = [];
    $result = app(IdentityEventConsumer::class)->run(fakeSource([envelope()], ready: false, acked: $acked), 10);

    // An acknowledged SQS message is gone for good, so a blank env var must not
    // be able to consume one.
    expect($result['blocked'])->toBeTrue()
        ->and($acked)->toBe([])
        ->and(IdentityInboxEntry::query()->count())->toBe(0);
});

it('applies an event to the mirror and records it once', function (): void {
    $acked = [];
    $result = app(IdentityEventConsumer::class)->run(fakeSource([envelope()], acked: $acked), 10);

    expect($result['applied'])->toBe(1)
        ->and($acked)->toHaveCount(1)
        ->and($this->organization->fresh()->name)->toBe('Renamed')
        ->and(IdentityInboxEntry::query()->count())->toBe(1);
});

it('rejects a duplicate delivery without re-applying it', function (): void {
    $one = envelope();

    app(IdentityEventConsumer::class)->run(fakeSource([$one]), 10);
    $this->organization->forceFill(['name' => 'Manually changed'])->save();

    $result = app(IdentityEventConsumer::class)->run(fakeSource([$one]), 10);

    // At-least-once delivery makes this routine, not exceptional.
    expect($result['duplicate'])->toBe(1)
        ->and($result['applied'])->toBe(0)
        ->and($this->organization->fresh()->name)->toBe('Manually changed');
});

it('DROPS an event that arrives behind one already applied', function (): void {
    // THE ordering invariant. SNS/SQS do not preserve order, so a late old event
    // must not roll the mirror back to a past state — silently.
    app(IdentityEventConsumer::class)->run(fakeSource([envelope(['sequence' => 20, 'data' => ['name' => 'Newer']])]), 10);

    $result = app(IdentityEventConsumer::class)
        ->run(fakeSource([envelope(['sequence' => 5, 'data' => ['name' => 'Older']])]), 10);

    expect($result['stale'])->toBe(1)
        ->and($result['applied'])->toBe(0)
        ->and($this->organization->fresh()->name)->toBe('Newer');
});

it('acknowledges only after the event is recorded', function (): void {
    $acked = [];
    app(IdentityEventConsumer::class)->run(fakeSource([envelope()], acked: $acked), 10);

    // Ack after, never before: acking first turns a crash in between into a
    // permanently lost event, because SQS has already dropped it.
    expect($acked)->toHaveCount(1)
        ->and(IdentityInboxEntry::query()->whereNotNull('applied_at')->count())->toBe(1);
});

it('records a deleted event but does NOT enact it', function (): void {
    $branch = Branch::factory()->create([
        'console_organization_id' => 'org-remote-1',
        'console_branch_id' => 'branch-1',
    ]);

    $result = app(IdentityEventConsumer::class)->run(fakeSource([envelope([
        'type' => 'jp.godx.identity.branch.deleted',
        'subject' => 'branch/branch-1',
        'data' => ['id' => 'branch-1'],
    ])]), 10);

    // Deleting here would cascade into rows that reference the branch — orders
    // point at branches — and a mirror is the wrong place to decide a shop's
    // history becomes unreachable. Recorded and counted, never enacted.
    expect($result['skipped'])->toBe(1)
        ->and($branch->fresh())->not->toBeNull()
        ->and(IdentityInboxEntry::query()->where('event_type', 'like', '%deleted')->count())->toBe(1);
});

it('never mirrors branch is_active, because that is a product decision (#3161)', function (): void {
    $branch = Branch::factory()->create([
        'console_organization_id' => 'org-remote-1',
        'console_branch_id' => 'branch-2',
        'is_active' => true,
        'name' => 'Old',
    ]);

    app(IdentityEventConsumer::class)->run(fakeSource([envelope([
        'type' => 'jp.godx.identity.branch.updated',
        'subject' => 'branch/branch-2',
        'data' => ['id' => 'branch-2', 'name' => 'New', 'is_active' => false],
    ])]), 10);

    // The name lands; is_active does not. `is_active = false` makes a branch
    // unresolvable in ResolveBranchFromSlug — the shop disappears from its own
    // URL — so another system must not flip it automatically.
    expect($branch->fresh()->name)->toBe('New')
        ->and((bool) $branch->fresh()->is_active)->toBeTrue();
});

it('ignores an event about a resource this app does not mirror', function (): void {
    $result = app(IdentityEventConsumer::class)->run(fakeSource([envelope([
        'type' => 'jp.godx.identity.service_user_access.created',
        'subject' => 'service_user_access/sua-1',
        'data' => ['id' => 'sua-1'],
    ])]), 10);

    // The feed is shared; a consumer takes what concerns it. Counted separately
    // so "ignored" never reads as "applied".
    expect($result['skipped'])->toBe(1)->and($result['applied'])->toBe(0);
});

it('#3199 — switches source driver from config alone', function (): void {
    config()->set('identity.source', 'null');
    expect(app(IdentitySourceManager::class)->source())->toBeInstanceOf(NullEventSource::class);

    config()->set('identity.source', 'sqs');
    expect(app(IdentitySourceManager::class)->source())->toBeInstanceOf(SqsEventSource::class);
});

it('#3199 — is on the scheduler', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->command, 'platform:consume-identity'));

    expect($event)->not->toBeNull('platform:consume-identity is not scheduled — register it in routes/console.php.')
        ->and($event->expression)->toBe('* * * * *');
});

it('#3199 — chỉ MỘT writer được ghi vào identity_inbox', function (): void {
    // `boundaries` trong config/domain-mutation-guard.php là KHAI BÁO; cưỡng chế
    // phải viết riêng cho từng aggregate (xem OrderPaymentLedgerWriterBoundaryTest).
    // Đã đo: thêm một writer thứ hai KHÔNG làm rào nào sẵn có đỏ — nên khai mà
    // không có bài này thì biên giới chỉ là trang trí.
    //
    // Nó quan trọng vì hai bất biến của sổ NHẬN nằm ở đúng chỗ ghi: unique
    // `event_id` chống trùng, và so `sequence` chống ghi đè ngược thời gian. Một
    // writer thứ hai đi vòng qua cả hai mà không có gì báo.
    $declared = config('domain-mutation-guard.aggregates.identity_feed.boundaries');

    expect($declared)->toBe(['app/Services/Platform/IdentityEventConsumer.php']);

    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());

        if (in_array($relative, $declared, true)) {
            continue;
        }

        // Bỏ comment trước khi khớp — một rào trượt vào văn xuôi là rào bị tắt.
        $source = php_strip_whitespace($file->getPathname());

        if (preg_match('/IdentityInboxEntry::(query\(\)->)?(create|forceCreate|insert|updateOrCreate|firstOrCreate|upsert)\s*\(/', $source) === 1) {
            $offenders[] = $relative;
        }
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n", [
        'Có writer thứ hai ghi vào identity_inbox.',
        'Sổ nhận chỉ được ghi qua IdentityEventConsumer — nơi giữ dedupe theo event_id',
        'và phép so sequence. Đi vòng qua nó là đi vòng qua cả hai bất biến.',
        implode(', ', $offenders),
    ]));
});
