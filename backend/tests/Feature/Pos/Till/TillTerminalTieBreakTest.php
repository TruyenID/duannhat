<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Omnify\Enums\TillSettlementKindEnum;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Str;

/*
 * Plan-046 P6-4 — bộ giải "phiên terminal MỚI NHẤT của một till".
 *
 * Luật: end DESC, rồi chain_sequence DESC, rồi id DESC. Hai nhánh ăn theo nó:
 * R1 quyết định lần mở ca kế NỐI chuỗi cũ hay bắt đầu chuỗi mới, R8 quyết định
 * guard tiền tệ có chặn hay không. Một lần chọn bấp bênh lật cả hai.
 *
 * Comparator có comment giải thích tie-break từ plan-046, nhưng KHÔNG có test
 * nào ghim nó — nên #1690 (đưa phép chọn xuống SQL để thôi nạp cả lịch sử) đã
 * có thể làm rơi một hàng hoà mà không gì kêu. Đây là bài test đó.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);

    $this->till = Till::create([
        'till_code' => 'TIE',
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'default_currency_code' => 'JPY',
        'variance_tolerance_amount' => 0,
    ]);
});

function tieSession(array $attrs): TillSession
{
    return TillSession::create(array_merge([
        'session_code' => 'S-'.Str::random(6),
        'status' => 'settled',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 0,
        'opened_at' => now()->subHours(9),
        'opened_by_id' => test()->user->id,
        'till_id' => test()->till->id,
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ], $attrs));
}

it('P6-4: chọn chain_sequence CAO HƠN khi hai phiên trùng mốc kết thúc', function () {
    $sameInstant = now()->subHour()->startOfSecond();

    // Cùng một tick: một phiên bàn giao và một phiên chốt hẳn. Nếu bộ giải chọn
    // nhầm cái `final`, lần mở ca kế sẽ bắt đầu CHUỖI MỚI thay vì nối tiếp.
    tieSession([
        'closed_at' => $sameInstant,
        'settlement_kind' => TillSettlementKindEnum::Final->value,
        'chain_sequence' => 1,
    ]);
    $handover = tieSession([
        'closed_at' => $sameInstant,
        'settlement_kind' => TillSettlementKindEnum::Handover->value,
        'chain_sequence' => 2,
    ]);

    $prev = app(TillSessionService::class)->previousTerminalSessionForTill($this->till->fresh());

    expect($prev)->not->toBeNull()
        ->and($prev['session']->id)->toBe($handover->id)
        ->and(app(TillSessionService::class)->branchHasOpenChain($this->shop->id))->toBeTrue();
});

/**
 * Mốc kết thúc là MAX của ba cột, không phải cột đầu tiên khác null.
 *
 * plan-032 cho phép quản lý đối soát tay một phiên đã HẾT HẠN, nên phiên đó
 * mang cả `expired_at` (lúc hết hạn) lẫn `closed_at` (lúc đối soát) — và
 * `closed_at` mới là mốc thật. `COALESCE` sẽ trả về mốc SỚM hơn và xếp phiên
 * này ra sau một phiên khác đáng lẽ cũ hơn nó.
 */
it('P6-4: mốc kết thúc là MAX của ba cột, không phải first-non-null', function () {
    tieSession([
        'closed_at' => now()->subHours(3),
        'settlement_kind' => TillSettlementKindEnum::Final->value,
        'chain_sequence' => 1,
    ]);

    // Hết hạn từ lâu, ĐỐI SOÁT TAY mới đây ⇒ mốc thật là closed_at.
    $reconciled = tieSession([
        'status' => 'expired',
        'expired_at' => now()->subHours(9),
        'closed_at' => now()->subMinutes(5),
        'settlement_kind' => TillSettlementKindEnum::Handover->value,
        'chain_sequence' => 2,
    ]);

    $prev = app(TillSessionService::class)->previousTerminalSessionForTill($this->till->fresh());

    expect($prev['session']->id)->toBe($reconciled->id)
        ->and(app(TillSessionService::class)->branchHasOpenChain($this->shop->id))->toBeTrue();
});

it('P6-4: phiên terminal không mang mốc nào thì không được chọn', function () {
    tieSession([
        'settlement_kind' => TillSettlementKindEnum::Handover->value,
        'chain_sequence' => 9,
    ]); // không closed_at / expired_at / abandoned_at

    expect(app(TillSessionService::class)->previousTerminalSessionForTill($this->till->fresh()))->toBeNull()
        ->and(app(TillSessionService::class)->branchHasOpenChain($this->shop->id))->toBeFalse();
});
