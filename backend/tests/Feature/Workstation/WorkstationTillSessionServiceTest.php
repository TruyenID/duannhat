<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\Till;
use App\Models\TillSession;
use App\Omnify\Enums\TillCountPhaseEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Pos\TillSessionService;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * Plan-047 thin-controller/fat-service — TillController::openSession + abandon
 * (workstation-authoritative shift lifecycle) moved into
 * TillSessionService::openFromWorkstation / abandonFromWorkstation. The HTTP
 * surface stays covered by WorkstationTillSyncUpTest; these hit the service.
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
    $this->till = Till::factory()->create([
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'variance_tolerance_amount' => 0,
    ]);
    $this->denomination = Denomination::create([
        'id' => (string) Str::uuid(),
        'currency_code' => 'JPY',
        'value' => 10000,
        'kind' => 'note',
        'label' => '¥10,000',
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $this->service = app(TillSessionService::class);

    $this->openData = fn (array $overrides = []): array => array_merge([
        'id' => (string) Str::uuid(),
        'session_code' => 'WS-'.Str::random(6),
        'currency_code' => 'JPY',
        'opened_at' => now()->toIso8601String(),
        'opening_counts' => [
            ['denomination_id' => $this->denomination->id, 'quantity' => 3], // 3 × ¥10,000 = ¥30,000
        ],
    ], $overrides);
});

it('opens a workstation shift, derives the float from counts, and points the till', function () {
    $data = ($this->openData)();

    [$session, $created] = $this->service->openFromWorkstation($this->branch->id, $data);

    expect($created)->toBeTrue()
        ->and($session->id)->toBe($data['id'])                       // device id honoured (HasUuids workaround)
        ->and($session->status->value)->toBe(TillSessionStatusEnum::Open->value)
        ->and((float) $session->opening_float_amount)->toBe(30000.0) // DERIVED, not client-supplied
        ->and($session->chain_sequence)->toBe(1);

    // The till now points at this session, and an opening count row was written.
    expect($this->till->fresh()->current_session_id)->toBe($session->id)
        ->and($session->openingCounts()->where('count_phase', TillCountPhaseEnum::Opening->value)->count())->toBe(1);
});

it('is idempotent — a retry of the same id returns the existing session with created=false', function () {
    $data = ($this->openData)();

    [$first] = $this->service->openFromWorkstation($this->branch->id, $data);
    [$second, $created] = $this->service->openFromWorkstation($this->branch->id, $data);

    expect($created)->toBeFalse()
        ->and($second->id)->toBe($first->id)
        ->and(TillSession::whereKey($data['id'])->count())->toBe(1);
});

it('rejects opening when the till already holds a DIFFERENT open shift (409 SHIFT_ALREADY_OPEN)', function () {
    // First shift claims the till.
    ($this->service->openFromWorkstation($this->branch->id, ($this->openData)()));

    // A second, different session id → the till pointer conflicts.
    try {
        $this->service->openFromWorkstation($this->branch->id, ($this->openData)());
        $this->fail('Expected a SHIFT_ALREADY_OPEN abort');
    } catch (HttpResponseException $e) {
        $response = $e->getResponse();
        expect($response->getStatusCode())->toBe(409)
            ->and(json_decode($response->getContent(), true)['code'])->toBe('SHIFT_ALREADY_OPEN');
    }
});

it('abandons an open shift and releases the till', function () {
    [$session] = $this->service->openFromWorkstation($this->branch->id, ($this->openData)());

    $abandoned = $this->service->abandonFromWorkstation($session, [
        'closed_at' => now()->toIso8601String(),
        'abandon_reason' => 'drawer jam',
    ]);

    expect($abandoned->status->value)->toBe(TillSessionStatusEnum::Abandoned->value)
        ->and($abandoned->abandon_reason)->toBe('drawer jam')
        ->and($abandoned->abandoned_at)->not->toBeNull()
        // closed_at stays NULL for ABANDONED (settled-only column).
        ->and($abandoned->closed_at)->toBeNull()
        ->and($this->till->fresh()->current_session_id)->toBeNull();
});

it('is idempotent on an already-abandoned shift', function () {
    [$session] = $this->service->openFromWorkstation($this->branch->id, ($this->openData)());
    $this->service->abandonFromWorkstation($session, ['closed_at' => now()->toIso8601String()]);

    $again = $this->service->abandonFromWorkstation($session->fresh(), ['closed_at' => now()->toIso8601String()]);

    expect($again->status->value)->toBe(TillSessionStatusEnum::Abandoned->value);
});

it('refuses to abandon a shift already in a terminal state (409 SHIFT_TERMINAL_STATE)', function () {
    [$session] = $this->service->openFromWorkstation($this->branch->id, ($this->openData)());
    $session->update(['status' => TillSessionStatusEnum::Settled->value]);

    try {
        $this->service->abandonFromWorkstation($session->fresh(), ['closed_at' => now()->toIso8601String()]);
        $this->fail('Expected a SHIFT_TERMINAL_STATE abort');
    } catch (HttpResponseException $e) {
        $response = $e->getResponse();
        expect($response->getStatusCode())->toBe(409)
            ->and(json_decode($response->getContent(), true)['code'])->toBe('SHIFT_TERMINAL_STATE');
    }
});

it('openFromWorkstation đóng dấu business_date theo timezone CHI NHÁNH, không phải UTC (#2781)', function () {
    // 2026-08-12 19:00 UTC = 2026-08-13 02:00 ICT. Ngày lịch UTC là 12; ngày
    // chi nhánh là 13. Đóng cứng UTC tại dòng stamp → bài này ĐỎ.
    $this->branch->update(['timezone' => 'Asia/Ho_Chi_Minh']);

    [$session] = $this->service->openFromWorkstation((string) $this->branch->id, ($this->openData)([
        'opened_at' => '2026-08-12T19:00:00Z',
    ]));

    expect(Carbon::parse($session->business_date)->toDateString())->toBe('2026-08-13');
});

it('openFromWorkstation: ba timezone cực trị ⇒ BA ngày kinh doanh khác nhau (#2781)', function () {
    // 2026-08-12 10:30 UTC:
    //   Pacific/Kiritimati (+14) → 2026-08-13 00:30
    //   Asia/Ho_Chi_Minh    (+7) → 2026-08-12 17:30
    //   Pacific/Pago_Pago  (−11) → 2026-08-11 23:30
    $openedAt = '2026-08-12T10:30:00Z';
    $expect = [
        'Pacific/Kiritimati' => '2026-08-13',
        'Asia/Ho_Chi_Minh' => '2026-08-12',
        'Pacific/Pago_Pago' => '2026-08-11',
    ];

    foreach ($expect as $tz => $day) {
        $branch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
            'timezone' => $tz,
        ]);
        Till::factory()->create([
            'branch_id' => $branch->id,
            'brand_id' => $this->brand->id,
            'organization_id' => $this->orgId,
            'variance_tolerance_amount' => 0,
        ]);
        [$session] = $this->service->openFromWorkstation((string) $branch->id, ($this->openData)([
            'opened_at' => $openedAt,
        ]));
        expect(Carbon::parse($session->business_date)->toDateString())->toBe($day);
    }
});
