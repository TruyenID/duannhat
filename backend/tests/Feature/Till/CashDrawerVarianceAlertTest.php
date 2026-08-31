<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\BrandOrderPolicy;
use App\Models\CashDeviceInventorySnapshot;
use App\Models\CashDeviceTransaction;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Modules\Notifications\Contracts\NotificationDispatcher;
use App\Modules\Notifications\Contracts\NotificationRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/*
 * #2937 — đối soát ba chân phải TỚI ĐƯỢC NGƯỜI.
 *
 * Nửa "PHẢI IM" ở đây dài hơn nửa "PHẢI KÊU", và đó là chủ ý: một cảnh báo tiền
 * kêu oan sẽ bị tắt, và lúc đó nó không còn canh gì. `undetermined` là ca nguy
 * hiểm nhất — nó xảy ra hàng ngày (máy offline lúc chốt ca), nên nếu nó kêu thì
 * cảnh báo này chết trong tuần đầu.
 */

final class FakeCashDrawerDispatcher implements NotificationDispatcher
{
    /** @var list<NotificationRequest> */
    public array $sent = [];

    public bool $throw = false;

    public function toRole(NotificationRequest $request, string|array $role, string $scopeKey, string $scopeId, Brand $brand): string
    {
        if ($this->throw) {
            throw new RuntimeException('audience rỗng');
        }

        $this->sent[] = $request;

        return 'n-'.count($this->sent);
    }

    /** @param iterable<Model> $recipients */
    public function toRecipients(NotificationRequest $request, iterable $recipients): string
    {
        return 'unused';
    }

    public function coversEmitter(string $modelAlias, string $triggerEvent, string $organizationId): bool
    {
        return true;
    }
}

beforeEach(function () {
    $this->fake = new FakeCashDrawerDispatcher;
    $this->app->instance(NotificationDispatcher::class, $this->fake);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->glory = PeripheralDevice::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'type' => 'coin_changer',
        'is_active' => true,
    ]);

    $till = Till::factory()->create(['branch_id' => $this->branch->id, 'organization_id' => $this->orgId]);
    $this->session = TillSession::factory()->create([
        'till_id' => $till->id,
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'status' => 'settled',
    ]);

    /** Dựng một ca đủ ba chân. */
    $this->scenario = function (int $open, int $close, int $deposited, int $dispensed, ?float $counted, ?float $expected, ?array $uncertain = null) {
        foreach ([['opening', $open, null], ['closing', $close, $uncertain]] as [$phase, $total, $unc]) {
            CashDeviceInventorySnapshot::factory()->create([
                'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
                'peripheral_device_id' => $this->glory->id, 'till_session_id' => $this->session->id,
                'count_phase' => $phase, 'denominations' => [], 'total_minor' => $total,
                'uncertain_denominations' => $unc, 'captured_at' => now(),
            ]);
        }
        CashDeviceTransaction::factory()->create([
            'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
            'peripheral_device_id' => $this->glory->id, 'till_session_id' => $this->session->id,
            'glory_transaction_id' => 'T-'.Str::random(6), 'outcome' => 'finish',
            'deposited_minor' => $deposited, 'dispensed_minor' => $dispensed,
        ]);
        $this->session->forceFill([
            'counted_cash_amount' => $counted,
            'expected_cash_amount' => $expected,
        ])->save();
    };

    $this->run = fn (array $opts = []) => $this->artisan('tills:reconcile-cash-drawers', $opts);
});

// ─────────────────────────────────────────────────────────────────────────────
// PHẢI KÊU
// ─────────────────────────────────────────────────────────────────────────────

it('#2937 lệch máy mà người khớp ⇒ báo, và nói rõ TIỀN RA KHỎI MÁY NGOÀI SỔ', function () {
    // máy kỳ vọng 10.000 + (5.000 − 1.000) = 14.000, đóng 13.000 ⇒ lệch −1.000.
    ($this->scenario)(10000, 13000, 5000, 1000, 14000.0, 14000.0);

    ($this->run)()->assertSuccessful();

    expect($this->fake->sent)->toHaveCount(1);

    $req = $this->fake->sent[0];

    expect($req->type)->toBe('till.cash_drawer_variance')
        ->and($req->params['verdict'])->toBe('cash_left_machine_off_book')
        // Hai con số phải cùng đi — chỉ một con số thì không phân loại được.
        ->and($req->params['machine_variance_minor'])->toBe(-1000)
        ->and($req->params['human_variance_minor'])->toBe(0)
        // HQ nhìn nhiều quán; một cảnh báo không nói rõ quán nào là không hành động được.
        ->and($req->params['branch_name'])->toBe($this->branch->name);
});

it('#2937 người đếm sai ⇒ báo đúng ô đó, không nhầm sang thiếu tiền', function () {
    ($this->scenario)(10000, 14000, 5000, 1000, 13000.0, 14000.0);

    ($this->run)()->assertSuccessful();

    expect($this->fake->sent[0]->params['verdict'])->toBe('human_count_error');
});

// ─────────────────────────────────────────────────────────────────────────────
// PHẢI IM — nửa quan trọng hơn
// ─────────────────────────────────────────────────────────────────────────────

it('#2937 ca KHỚP ⇒ KHÔNG báo gì', function () {
    ($this->scenario)(10000, 14000, 5000, 1000, 14000.0, 14000.0);

    ($this->run)()->assertSuccessful();

    expect($this->fake->sent)->toHaveCount(0);
});

it('#2937 undetermined KHÔNG báo — nếu không cảnh báo này chết trong tuần đầu', function () {
    // Máy khai 在高不確定 ở một mệnh giá ⇒ không kết luận được. Ca này xảy ra
    // hàng ngày; kêu mỗi lần là dạy người ta tắt cảnh báo.
    ($this->scenario)(10000, 13000, 5000, 1000, 14000.0, 14000.0, ['5000']);

    ($this->run)()->assertSuccessful();

    expect($this->fake->sent)->toHaveCount(0);
});

it('#2937 máy im lúc chốt ca ⇒ KHÔNG báo, và KHÔNG chặn lượt quét', function () {
    // Không có ảnh chụp `closing` ⇒ ca này không được chọn ngay từ truy vấn.
    CashDeviceInventorySnapshot::factory()->create([
        'organization_id' => $this->orgId, 'branch_id' => $this->branch->id,
        'peripheral_device_id' => $this->glory->id, 'till_session_id' => $this->session->id,
        'count_phase' => 'opening', 'denominations' => [], 'total_minor' => 10000,
        'uncertain_denominations' => null, 'captured_at' => now(),
    ]);

    ($this->run)()->assertSuccessful();

    expect($this->fake->sent)->toHaveCount(0);
});

it('#2937 chạy HAI LẦN vẫn chỉ MỘT thông báo — khoá là ca, không phải lượt chạy', function () {
    ($this->scenario)(10000, 13000, 5000, 1000, 14000.0, 14000.0);

    ($this->run)()->assertSuccessful();
    ($this->run)()->assertSuccessful();

    // Nền tảng thông báo khử trùng theo `idempotencyKey`; ở đây đo rằng khoá
    // gửi lên KHÔNG mang thời điểm chạy — nếu nó mang, mỗi giờ một thông báo.
    expect($this->fake->sent)->toHaveCount(2)
        ->and($this->fake->sent[0]->idempotencyKey)->toBe($this->fake->sent[1]->idempotencyKey)
        ->and($this->fake->sent[0]->idempotencyKey)->toBe('cash-drawer-variance:'.$this->session->id);
});

it('#2937 brand nới ngưỡng ⇒ IM, dù lệch vẫn được ĐO', function () {
    BrandOrderPolicy::factory()->create([
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'cash_variance_tolerance_minor' => 5000,
    ]);
    ($this->scenario)(10000, 13000, 5000, 1000, 14000.0, 14000.0);

    ($this->run)()->assertSuccessful();

    expect($this->fake->sent)->toHaveCount(0);
});

it('#2937 cảnh báo hỏng KHÔNG làm hỏng lượt quét', function () {
    $this->fake->throw = true;
    ($this->scenario)(10000, 13000, 5000, 1000, 14000.0, 14000.0);

    // Đối soát là thứ ĐỌC dữ liệu; cảnh báo chỉ là thông báo về nó. Audience
    // rỗng (chưa ai giữ role) cũng rơi vào đây.
    ($this->run)()->assertSuccessful();
});

it('#2937 --dry-run KHÔNG gửi gì', function () {
    ($this->scenario)(10000, 13000, 5000, 1000, 14000.0, 14000.0);

    ($this->run)(['--dry-run' => true])->assertSuccessful();

    expect($this->fake->sent)->toHaveCount(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Ngưỡng qua ENDPOINT (#2622 — validate() strip mọi key không có rule)
// ─────────────────────────────────────────────────────────────────────────────

it('#2937 đặt ngưỡng qua API và nó THẬT SỰ tới DB', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($user, $this->orgId);
    $this->actingAs($user);

    $this->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
        'cash_variance_tolerance_minor' => 750,
    ])->assertOk();

    expect((int) BrandOrderPolicy::query()->where('brand_id', $this->brand->id)->value('cash_variance_tolerance_minor'))
        ->toBe(750);
});

it('#2937 ngưỡng 0 được TÔN TRỌNG — nó nghĩa là "báo mọi lệch"', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($user, $this->orgId);
    $this->actingAs($user);

    $this->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
        'cash_variance_tolerance_minor' => 0,
    ])->assertOk();

    // Ép 0 về mặc định là âm thầm cướp mất lựa chọn của brand.
    expect((int) BrandOrderPolicy::query()->where('brand_id', $this->brand->id)->value('cash_variance_tolerance_minor'))
        ->toBe(0);
});

it('#2937 ngưỡng âm bị từ chối', function () {
    $user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($user, $this->orgId);
    $this->actingAs($user);

    $this->patchJson("/api/v1/hq/{$this->brand->slug}/settings/brand", [
        'cash_variance_tolerance_minor' => -1,
    ])->assertStatus(422);
});
