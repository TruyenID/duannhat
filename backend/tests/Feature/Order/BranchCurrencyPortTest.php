<?php

declare(strict_types=1);

/**
 * #1589 — cổng `BranchCurrency` KHÔNG được tự áp mặc định.
 *
 * Sáu chỗ gọi có sáu fallback khác nhau, và khác nhau CÓ LÝ DO:
 *
 *   StripePaymentService · StripeTerminalService → config('services.stripe.currency', 'jpy')
 *   TillSessionService                           → till.default_currency_code ?? 'JPY'
 *   OrderPaymentService                          → 'JPY'
 *   CouponService                                → giữ nguyên null (caller trên nữa quyết)
 *   CustomerPointService                         → tra bảng tỉ lệ theo mã, null = mặc định cấu hình
 *
 * Nếu cổng lặng lẽ trả `'JPY'` khi chưa cấu hình, năm trong sáu chỗ đó đổi hành
 * vi TIỀN TỆ mà không test nào ở tầng chúng nó kêu. Bài test này ghim đúng chỗ
 * đó: chưa cấu hình ⇒ `null`, và cổng không viết hoa hộ ai.
 */

use App\Models\Branch;
use App\Models\ShopOrderSetting;
use App\Services\Order\Contracts\BranchCurrency;
use Illuminate\Support\Str;

it('cổng có binding thật, không phải interface rỗng', function () {
    expect(app(BranchCurrency::class))->toBeInstanceOf(BranchCurrency::class);
});

it('chi nhánh CHƯA cấu hình trả null, KHÔNG phải JPY', function () {
    $branch = Branch::factory()->create(['console_organization_id' => (string) Str::uuid()]);

    expect(app(BranchCurrency::class)->codeFor((string) $branch->id))->toBeNull()
        ->and(app(BranchCurrency::class)->codeFor((string) Str::uuid()))->toBeNull();
});

it('trả đúng mã đã cấu hình, giữ nguyên hoa/thường', function () {
    $orgId = (string) Str::uuid();
    $branch = Branch::factory()->create(['console_organization_id' => $orgId]);
    ShopOrderSetting::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branch->id,
        'currency_code' => 'usd',
    ]);

    // Không `strtoupper()` trong cổng: hai chỗ gọi so chuỗi này với khoá cấu
    // hình chữ thường, nên chuẩn hoá hộ là đổi hành vi của chúng.
    expect(app(BranchCurrency::class)->codeFor((string) $branch->id))->toBe('usd');
});
