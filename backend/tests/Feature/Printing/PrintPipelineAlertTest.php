<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Notification;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\PrintJobResolution;
use App\Models\Role;
use App\Models\User;
use App\Services\Printing\Enums\PrintJobKind;
use App\Services\Printing\Enums\PrintJobStatus;
use App\Services\Printing\Enums\PrintTransport;
use App\Services\Printing\PrintPipelineAlertService;
use Database\Seeders\IamSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * plan-052 M2 / T2.3 — the alert sweep and its debounce.
 *
 * An alert channel is only worth having while people still read it, so the
 * behaviour under test is as much about SILENCE as about noise:
 *
 *   - one alert per condition per window (G4, plan-050);
 *   - **the cooldown is released when the condition clears**, so a printer
 *     that dies twice in an evening is reported twice — the second occurrence
 *     is news, not a repeat;
 *   - silent-printer alerts debounce PER PRINTER: three dead machines are
 *     three repairs, and collapsing them costs a day on the second and third.
 */
beforeEach(function () {
    Cache::flush();

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'timezone' => 'Asia/Tokyo',
        'is_active' => true,
    ]);

    // Somebody has to be on the receiving end or the platform refuses the
    // dispatch (empty audience) and we would be testing the catch block.
    $this->manager = alertShopManager($this->branch->id);

    config([
        'print_jobs.alerts.enabled' => true,
        'print_jobs.alerts.printer_silence_minutes' => 60,
        'print_jobs.alerts.needs_attention_threshold' => 3,
        'print_jobs.alerts.money_document_threshold' => 1,
        'print_jobs.alerts.debounce_minutes' => 120,
    ]);
});

/** A user holding `shop-manager` at one branch — the alert audience. */
function alertShopManager(string $branchId): User
{
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    $user = User::factory()->create(['console_organization_id' => test()->orgId]);
    $user->assignRole('shop-manager', test()->orgId, $branchId);

    return $user;
}

/**
 * #2849 — org-admin phạm vi TOÀN tổ chức (`branch_id = NULL` = all_branches_access).
 *
 * Đây là hình dạng của production: không ai giữ `shop-manager`, người duy nhất
 * phủ được 本郷店 là một admin tổ chức với `branch_id` NULL.
 */
function alertOrgAdmin(): User
{
    if (! Permission::query()->exists()) {
        (new IamSeeder)->run();
    }

    $user = User::factory()->create(['console_organization_id' => test()->orgId]);
    $user->assignRole('org-admin', test()->orgId, null);

    return $user;
}

/**
 * #2859 — dựng đúng kịch bản production: chi nhánh KHÔNG có ai giữ `shop-manager`.
 *
 * `beforeEach` luôn tạo một shop-manager (đúng, vì hầu hết bài ở đây đo debounce
 * chứ không đo audience). Nhưng bài "tới org-admin khi quán không có ai giữ
 * shop-manager" thì kịch bản ấy **chưa bao giờ chạy**: khẳng định
 * `count($recipientIds) > 0` xanh nhờ chính người của `beforeEach`, nên bài đó
 * đo một thế giới không phải thế giới nó khai trong tựa đề.
 *
 * Kịch bản thật là thứ đã gây 34 lần `print_alert_dispatch_skipped` trên
 * production.
 */
function alertRemoveShopManagers(): void
{
    DB::table('role_user_pivots')
        ->whereIn('role_id', Role::query()->where('slug', 'shop-manager')->pluck('id'))
        ->delete();
}

/**
 * #2859 — gắn vai theo hình dạng production: slug `tempo-*`, không phải slug ma trận.
 *
 * Trên production KHÔNG AI giữ slug ma trận — cả 4 user đều giữ `tempo-admin`
 * (CLAUDE.md § "Vai người dùng"); `RoleTemplateMatrix::equivalentSlugs()` mới là
 * thứ bắc cầu. Fixture chỉ dùng `org-admin` sẽ ghim một thế giới không tồn tại,
 * và cây cầu ấy gãy thì không bài nào ở đây đỏ.
 *
 * Cùng khuôn với `wsAlertAssignRole` của #2852.
 */
function alertAssignRawRole(User $user, string $slug, string $organizationId, ?string $branchId): void
{
    $role = Role::firstOrCreate(
        ['slug' => $slug],
        [
            'id' => (string) Str::uuid(),
            'console_organization_id' => $organizationId,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'level' => 100,
        ],
    );

    DB::table('role_user_pivots')->insert([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'organization_id' => $organizationId,
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function alertSweep(): array
{
    return app(PrintPipelineAlertService::class)->sweep(test()->branch->id);
}

function silentPrinter(array $overrides = []): Printer
{
    return Printer::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'transport' => PrintTransport::WsLan->value,
        'last_seen_at' => now()->subHours(5),
        'is_active' => true,
    ], $overrides));
}

function alertJob(array $overrides = []): PrintJob
{
    return PrintJob::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'transport' => PrintTransport::WsLan->value,
        'kind' => PrintJobKind::Kitchen->value,
        'status' => PrintJobStatus::NeedsAttention->value,
    ], $overrides));
}

function emittedTypes(array $result): array
{
    return array_values(array_map(static fn (array $a): string => $a['type'], $result['emitted']));
}

describe('what earns an alert', function () {
    it('alerts on a silent printer', function () {
        silentPrinter();

        $result = alertSweep();

        expect(emittedTypes($result))->toBe([PrintPipelineAlertService::TYPE_PRINTER_SILENT])
            ->and(Notification::query()->where('type', 'print.printer_silent')->count())->toBe(1);
    });

    it('says nothing when every printer is talking', function () {
        silentPrinter(['last_seen_at' => now()->subMinutes(2)]);

        expect(alertSweep()['emitted'])->toBe([]);
    });

    it('alerts once the needs_attention backlog crosses the threshold', function () {
        for ($i = 0; $i < 3; $i++) {
            alertJob();
        }

        expect(emittedTypes(alertSweep()))->toContain(PrintPipelineAlertService::TYPE_NEEDS_ATTENTION);
    });

    it('stays quiet one job below the threshold', function () {
        alertJob();
        alertJob();

        expect(emittedTypes(alertSweep()))->not->toContain(PrintPipelineAlertService::TYPE_NEEDS_ATTENTION);
    });

    it('alerts on the FIRST failed money document', function () {
        alertJob(['kind' => PrintJobKind::Receipt->value, 'status' => PrintJobStatus::Failed->value]);

        expect(emittedTypes(alertSweep()))->toContain(PrintPipelineAlertService::TYPE_MONEY_DOCUMENT_FAILED);
    });

    it('does not treat a failed kitchen ticket as a money-document alert', function () {
        alertJob(['kind' => PrintJobKind::Kitchen->value, 'status' => PrintJobStatus::Failed->value]);

        expect(emittedTypes(alertSweep()))->not->toContain(PrintPipelineAlertService::TYPE_MONEY_DOCUMENT_FAILED);
    });

    it('stops counting a money document a manager already ruled on', function () {
        $job = alertJob(['kind' => PrintJobKind::Receipt->value, 'status' => PrintJobStatus::Failed->value]);

        PrintJobResolution::query()->create([
            'id' => (string) Str::uuid(),
            'print_job_id' => $job->id,
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'resolution' => 'printed_by_hand',
            'reason' => 'wrote it out by hand',
            'resolved_by_id' => $this->manager->id,
            'resolved_at' => now(),
        ]);

        expect(emittedTypes(alertSweep()))->not->toContain(PrintPipelineAlertService::TYPE_MONEY_DOCUMENT_FAILED);
    });

    it('ignores a money-document failure older than the lookback window', function () {
        config(['print_jobs.alerts.money_document_lookback_hours' => 24]);

        alertJob([
            'kind' => PrintJobKind::Receipt->value,
            'status' => PrintJobStatus::Failed->value,
            'created_at' => now()->subDays(3),
        ]);

        expect(emittedTypes(alertSweep()))->not->toContain(PrintPipelineAlertService::TYPE_MONEY_DOCUMENT_FAILED);
    });

    it('is switched off entirely by the flag', function () {
        config(['print_jobs.alerts.enabled' => false]);

        silentPrinter();

        expect(alertSweep()['emitted'])->toBe([])
            ->and(Notification::query()->count())->toBe(0);
    });
});

describe('debounce (G4)', function () {
    it('alerts once, then holds its tongue for the whole window', function () {
        silentPrinter();

        expect(alertSweep()['emitted'])->toHaveCount(1);

        $second = alertSweep();

        expect($second['emitted'])->toBe([])
            ->and($second['suppressed'])->toHaveCount(1)
            ->and(Notification::query()->where('type', 'print.printer_silent')->count())->toBe(1);
    });

    it('alerts again once the window has passed', function () {
        config(['print_jobs.alerts.debounce_minutes' => 60]);

        silentPrinter();

        expect(alertSweep()['emitted'])->toHaveCount(1);

        $this->travel(61)->minutes();

        expect(alertSweep()['emitted'])->toHaveCount(1);

        $this->travelBack();
    });

    it('re-arms as soon as the condition clears, so a repeat is reported immediately', function () {
        $printer = silentPrinter();

        expect(alertSweep()['emitted'])->toHaveCount(1);

        // The machine comes back.
        $printer->update(['last_seen_at' => now()]);
        $recovery = alertSweep();

        expect($recovery['emitted'])->toBe([])
            ->and($recovery['cleared'])->not->toBeEmpty();

        // …and dies again, well inside the 120-minute window.
        $printer->update(['last_seen_at' => now()->subHours(5)]);

        expect(alertSweep()['emitted'])->toHaveCount(1);
    });

    it('re-arms a threshold alert the same way (over → under → over)', function () {
        for ($i = 0; $i < 3; $i++) {
            alertJob();
        }

        expect(emittedTypes(alertSweep()))->toContain(PrintPipelineAlertService::TYPE_NEEDS_ATTENTION);

        // Two of the three get resolved by hand — back under the threshold.
        PrintJob::query()
            ->where('branch_id', $this->branch->id)
            ->limit(2)
            ->get()
            ->each(fn (PrintJob $job) => PrintJob::query()->whereKey($job->id)->delete());

        $recovery = alertSweep();
        expect(emittedTypes($recovery))->not->toContain(PrintPipelineAlertService::TYPE_NEEDS_ATTENTION);

        // It builds back up.
        alertJob();
        alertJob();

        expect(emittedTypes(alertSweep()))->toContain(PrintPipelineAlertService::TYPE_NEEDS_ATTENTION);
    });

    it('debounces PER PRINTER — three dead machines are three alerts, once each', function () {
        $a = silentPrinter();
        $b = silentPrinter();
        $c = silentPrinter();

        $first = alertSweep();

        expect($first['emitted'])->toHaveCount(3)
            ->and(collect($first['emitted'])->pluck('printer_id')->sort()->values()->all())
            ->toBe(collect([$a->id, $b->id, $c->id])->sort()->values()->all());

        // Second tick: all three still dead, nothing repeats.
        expect(alertSweep()['emitted'])->toBe([]);

        // A FOURTH machine dies — it alerts immediately, unaffected by the
        // other three's cooldowns.
        $d = silentPrinter();

        $later = alertSweep();

        expect($later['emitted'])->toHaveCount(1)
            ->and($later['emitted'][0]['printer_id'])->toBe($d->id);
    });

    it('never debounces one branch behind another', function () {
        $otherBranch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
        ]);
        alertShopManager($otherBranch->id);

        for ($i = 0; $i < 3; $i++) {
            alertJob();
            PrintJob::factory()->create([
                'organization_id' => $this->orgId,
                'branch_id' => $otherBranch->id,
                'status' => PrintJobStatus::NeedsAttention->value,
            ]);
        }

        $result = app(PrintPipelineAlertService::class)->sweep();

        $backlogAlerts = collect($result['emitted'])
            ->where('type', PrintPipelineAlertService::TYPE_NEEDS_ATTENTION);

        expect($backlogAlerts)->toHaveCount(2)
            ->and($backlogAlerts->pluck('branch_id')->unique())->toHaveCount(2);
    });
});

describe('resilience', function () {
    it('does not blow up when nobody holds shop-manager at the branch', function () {
        $orphanBranch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
        ]);

        Printer::factory()->create([
            'organization_id' => $this->orgId,
            'branch_id' => $orphanBranch->id,
            'last_seen_at' => now()->subDays(2),
            'is_active' => true,
        ]);

        // The dispatch fails (empty audience) and is swallowed; the sweep still
        // reports what it found rather than aborting.
        $result = app(PrintPipelineAlertService::class)->sweep($orphanBranch->id);

        expect($result['emitted'])->toHaveCount(1)
            ->and(Notification::query()->count())->toBe(0);
    });
});

/*
 * #2849 — cảnh báo máy in phải tới được MỘT CON NGƯỜI.
 *
 * Bài `does not blow up when nobody holds shop-manager` ngay trên ghim đúng vế
 * fail-open, và đó là hành vi đúng. Nhưng nó cũng mô tả chính xác production:
 * không ai giữ `shop-manager`, nên nhánh "không nổ" là nhánh chạy MỌI LẦN, và
 * máy in im tiếng suốt mà không ai biết. `emitted` đếm thứ sweep TÌM THẤY, không
 * đếm thứ ai đó NHẬN ĐƯỢC — khẳng định vào nó sẽ xanh vĩnh viễn.
 *
 * Vì thế hai chiều dưới đây đo `recipients`, không đo `emitted`.
 */
describe('#2849 audience', function () {
    it('tới org-admin toàn tổ chức khi quán không có ai giữ shop-manager', function () {
        // #2859 — GỠ người của `beforeEach` trước. Thiếu dòng này thì tựa đề nói
        // "không có ai giữ shop-manager" trong khi vẫn có một người, và khẳng
        // định `> 0` xanh nhờ chính người đó — bài đo nhầm thế giới.
        alertRemoveShopManagers();

        $orgAdmin = alertOrgAdmin();

        silentPrinter();
        alertSweep();

        $notification = Notification::query()->latest('id')->first();

        expect($notification)->not->toBeNull();

        $recipientIds = $notification->recipients()->pluck('recipient_id')->all();

        // `branch_id = NULL` nghĩa là MỌI chi nhánh (#2460), không phải "không
        // chi nhánh nào" — nếu `coversBranch()` đọc ngược, người này biến mất.
        expect(count($recipientIds))->toBeGreaterThan(0)
            ->and($recipientIds)->toContain($orgAdmin->id);
    });

    it('vẫn ưu tiên người quán — shop-manager gắn chi nhánh nhận cùng lúc', function () {
        $shopManager = alertShopManager((string) test()->branch->id);
        $orgAdmin = alertOrgAdmin();

        silentPrinter();
        alertSweep();

        $recipientIds = Notification::query()->latest('id')->first()
            ->recipients()->pluck('recipient_id')->all();

        // Thêm `org-admin` KHÔNG được thay thế người quán. Cả hai cùng nhận.
        expect($recipientIds)->toContain($shopManager->id)
            ->and($recipientIds)->toContain($orgAdmin->id);
    });

    it('#2859 người giữ slug PRODUCTION `tempo-admin` vẫn nhận được', function () {
        // Trên production không ai giữ slug ma trận; cả 4 user đều giữ
        // `tempo-admin`. Cây cầu là `RoleTemplateMatrix::equivalentSlugs()`.
        //
        // Mọi bài audience khác ở đây gắn `org-admin` trực tiếp, nên nếu cây cầu
        // ấy gãy thì **không bài nào đỏ** trong khi production im hoàn toàn —
        // đúng lớp lỗi đã cháy bốn lần, chỉ khác là lần này không phải typo mà
        // là một phép ánh xạ.
        alertRemoveShopManagers();

        $admin = User::factory()->create(['console_organization_id' => $this->orgId]);
        alertAssignRawRole($admin, 'tempo-admin', $this->orgId, null);

        silentPrinter();
        alertSweep();

        $recipientIds = Notification::query()->latest('id')->first()
            ?->recipients()->pluck('recipient_id')->all() ?? [];

        expect(count($recipientIds))->toBeGreaterThan(0)
            ->and($recipientIds)->toContain($admin->id);
    });

    it('org-admin của tổ chức KHÁC không nhận nhầm', function () {
        alertOrgAdmin();

        // #2859 — pivot phải mang id LOCAL của tổ chức, không phải console id.
        // Bản cũ gắn vai vào `$otherConsoleOrgId`, tức một `organizations.id`
        // KHÔNG tồn tại; người lạ ấy không thuộc tổ chức nào nên bài xanh vì
        // fixture hỏng chứ không vì phạm vi đúng. Hai id cố tình khác nhau vì
        // production khác nhau.
        $otherConsoleOrgId = (string) Str::uuid();
        $otherOrg = Organization::factory()->create(['console_organization_id' => $otherConsoleOrgId]);
        $stranger = User::factory()->create(['console_organization_id' => $otherConsoleOrgId]);
        alertAssignRawRole($stranger, 'tempo-admin', (string) $otherOrg->id, null);

        silentPrinter();
        alertSweep();

        $recipientIds = Notification::query()->latest('id')->first()
            ->recipients()->pluck('recipient_id')->all();

        // Nới phạm vi cho hết lỗi thì bài này đỏ: một cảnh báo gửi nhầm sang tổ
        // chức khác tệ hơn một cảnh báo không gửi.
        expect($recipientIds)->not->toContain($stranger->id);
    });
});
