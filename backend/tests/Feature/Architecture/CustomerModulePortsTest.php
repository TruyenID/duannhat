<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\ShopOrderSetting;
use App\Models\Table;
use App\Models\TaxType;
use App\Models\Zone;
use App\Services\Customer\OrderClosingService;
use App\Services\Order\Contracts\BranchDefaultTaxType;
use App\Services\Order\Contracts\BranchOpeningWindow;
use App\Services\Order\Contracts\BranchSplitBillPolicy;
use App\Services\Order\Contracts\OrderQueryPort;
use App\Services\Shop\BranchOpeningHours;
use App\Services\Shop\Contracts\TableOccupancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * #962 — tám cạnh `App\Services\Customer\*` đi qua HỢP ĐỒNG thay vì cầm thẳng
 * model/service của module khác.
 *
 * Deptrac đã ghim phần "không còn cạnh". Cái nó KHÔNG ghim — và là thứ duy nhất
 * có thể hỏng trong im lặng — là **cổng có trả lời đúng bằng đường cũ không**.
 * Mỗi bài dưới đây gắn với một quyết định nghiệp vụ mà bước dời chỗ vừa đi
 * xuyên qua:
 *
 *   · dung sai làm tròn khi chốt đơn (#821 E3 — tiền)
 *   · bậc "mặc định chi nhánh" của chuỗi phân giải thuế (plan-043 §7)
 *   · ba tham số chia hoá đơn, gồm mặc định `JPY` của #815 (tiền)
 *   · bàn về `free` hay `cleaning` sau khi trả tiền (#491)
 *   · giờ mở cửa quyết đơn takeaway có đặt được không (#1160/#1167)
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
});

// =============================================================================
//  Cổng phải TỒN TẠI và giải được — một interface không bind là cổng trang trí
// =============================================================================

it('ba cổng mới đều resolve được từ container', function (string $contract) {
    expect(app($contract))->toBeInstanceOf($contract);
})->with([
    BranchDefaultTaxType::class,
    BranchSplitBillPolicy::class,
    BranchOpeningWindow::class,
]);

/**
 * Luật `PublishedContracts` (#1596/#1598): một hợp đồng công bố KHÔNG được mang
 * model Eloquent của module chủ sở hữu. Deptrac cưỡng chế điều này, nhưng chỉ
 * khi ai đó chạy deptrac. Ghim lại ở đây để lần sửa chữ ký tiếp theo đỏ ngay
 * trong suite.
 *
 * `BranchOpeningWindow` CỐ Ý nhận `App\Models\Branch` — đó là TenancyKernel, mọi
 * module được phép chạm, nên nó không nằm trong danh sách bị cấm.
 */
it('cổng công bố không rò model của module nào', function (string $contract) {
    // Bỏ comment TRƯỚC khi soi: docblock của các cổng này CỐ Ý nhắc tên model
    // trong dấu backtick để giải thích vì sao chúng KHÔNG xuất hiện trong chữ
    // ký. Soi cả comment thì bài test cấm luôn việc ghi lại lý do — và tệ hơn,
    // nó biến `pint` (thứ kéo `{@see \App\...}` thành `use` thật) thành cái duy
    // nhất bị bắt, chứ không phải chữ ký thật sự rò.
    $code = '';
    foreach (token_get_all(file_get_contents((new ReflectionClass($contract))->getFileName())) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    foreach (['ShopOrderSetting', 'CustomerOrder', 'CustomerOrderItem', 'TaxType', 'Table'] as $model) {
        expect($code)->not->toContain('App\\Models\\'.$model);
    }
})->with([
    BranchDefaultTaxType::class,
    BranchSplitBillPolicy::class,
    BranchOpeningWindow::class,
]);

// =============================================================================
//  TIỀN — dung sai làm tròn khi chốt đơn (#821 E3)
// =============================================================================

/**
 * `OrderPaymentService` / `StripePaymentService` gọi
 * `OrderQueryPort::isPaidInFull` thay cho `OrderClosingService::isPaidEnough`.
 * Cổng CHUYỂN TIẾP, không tính lại — nên hai bên phải trả lời giống hệt nhau,
 * kể cả ở đúng chỗ đã từng đẻ ra doanh thu ma.
 */
it('cổng isPaidInFull trả lời y hệt isPaidEnough — JPY: thiếu ¥2 vẫn coi là đủ', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'JPY',
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'total_amount' => 1000,
        'paid_amount' => 998,
    ]);

    $viaPort = app(OrderQueryPort::class)->isPaidInFull($this->orgId, (string) $order->id);

    expect($viaPort)->toBeTrue()
        ->and($viaPort)->toBe(OrderClosingService::isPaidEnough($order));
});

/**
 * #821 E3 nguyên bản: 98.01 trên hoá đơn 100.00 USD KHÔNG được tự đóng. Dung sai
 * lấy từ `shop_order_settings.currency_code`, nên trên USD nó là 0.02, không
 * phải 2. Nếu ai đó "đơn giản hoá" cổng thành một phép trừ, bài này đỏ.
 */
it('cổng isPaidInFull giữ dung sai theo TIỀN TỆ — USD: thiếu 1.99 KHÔNG phải đã trả đủ', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'USD',
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'total_amount' => 100.00,
        'paid_amount' => 98.01,
    ]);

    $viaPort = app(OrderQueryPort::class)->isPaidInFull($this->orgId, (string) $order->id);

    expect($viaPort)->toBeFalse()
        ->and($viaPort)->toBe(OrderClosingService::isPaidEnough($order));
});

it('đơn của tổ chức khác KHÔNG phải "đã trả đủ" — cổng lọc theo organization', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'total_amount' => 100,
        'paid_amount' => 100,
    ]);

    expect(app(OrderQueryPort::class)->isPaidInFull((string) Str::uuid(), (string) $order->id))->toBeFalse();
});

// =============================================================================
//  THUẾ — bậc 5 của chuỗi phân giải (plan-043 §7)
// =============================================================================

it('cổng BranchDefaultTaxType trả về đúng id loại thuế mặc định của chi nhánh', function () {
    $taxType = TaxType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'rate' => 8,
    ]);

    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'default_tax_type_id' => $taxType->id,
    ]);

    expect(app(BranchDefaultTaxType::class)->defaultTaxTypeIdForBranch((string) $this->branch->id))
        ->toBe((string) $taxType->id);
});

it('chi nhánh chưa có shop_order_settings → null, KHÔNG ném lỗi', function () {
    expect(app(BranchDefaultTaxType::class)->defaultTaxTypeIdForBranch((string) $this->branch->id))
        ->toBeNull();
});

it('shop_order_settings có nhưng để trống default_tax_type_id → null', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'default_tax_type_id' => null,
    ]);

    expect(app(BranchDefaultTaxType::class)->defaultTaxTypeIdForBranch((string) $this->branch->id))
        ->toBeNull();
});

/**
 * Bài quan trọng nhất của cụm thuế: `TaxResolver` giờ hỏi cổng lấy ID rồi TỰ nạp
 * `TaxType`. Kết quả phải bằng đúng thứ quan hệ `defaultTaxType` từng trả về —
 * cùng một hàng, cùng một `rate`.
 */
it('TaxResolver vẫn phân giải bậc "mặc định chi nhánh" ra đúng loại thuế cũ', function () {
    $taxType = TaxType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'rate' => 8,
    ]);

    $setting = ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'default_tax_type_id' => $taxType->id,
    ]);

    $viaPort = app(BranchDefaultTaxType::class)->defaultTaxTypeIdForBranch((string) $this->branch->id);

    // Đường CŨ: quan hệ Eloquent trên chính hàng setting đó.
    expect(TaxType::query()->find($viaPort)?->getKey())
        ->toBe($setting->fresh()->defaultTaxType?->getKey())
        ->and(TaxType::query()->find($viaPort)?->rate)->toEqual($taxType->rate);
});

// =============================================================================
//  TIỀN — ba tham số chia hoá đơn, gồm mặc định JPY của #815
// =============================================================================

it('cổng BranchSplitBillPolicy đọc đúng ba cột của chi nhánh', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'split_bill_rounding_mode' => 'up',
        'currency_code' => 'VND',
        'service_charge_rate' => 5.5,
    ]);

    $settings = app(BranchSplitBillPolicy::class)->forBranch((string) $this->branch->id);

    expect($settings->roundingMode)->toBe('up')
        ->and($settings->currencyCode)->toBe('VND')
        ->and($settings->serviceChargeRate)->toBe(5.5);
});

/**
 * Đường cũ viết mặc định inline (`?? 'auto'`, `?? 'JPY'` theo #815, `?? 0`) và
 * một shop nửa cấu hình vẫn chia được bill. Cổng phải giữ nguyên cả ba — đổi
 * `currencyCode` mặc định là đổi cỡ dung sai làm tròn, tức đổi TIỀN.
 */
it('chi nhánh chưa cấu hình → auto / JPY / 0, không ném lỗi', function () {
    $settings = app(BranchSplitBillPolicy::class)->forBranch((string) $this->branch->id);

    expect($settings->roundingMode)->toBe('auto')
        ->and($settings->currencyCode)->toBe('JPY')
        ->and($settings->serviceChargeRate)->toBe(0.0);
});

it('branchId null → cùng bộ mặc định, không truy vấn hụt', function () {
    $settings = app(BranchSplitBillPolicy::class)->forBranch(null);

    expect($settings->roundingMode)->toBe('auto')
        ->and($settings->currencyCode)->toBe('JPY')
        ->and($settings->serviceChargeRate)->toBe(0.0);
});

// =============================================================================
//  BÀN — free hay cleaning sau khi trả tiền (#491)
// =============================================================================

it('releaseByOrderAfterPayment nhả bàn về free và xoá current_order_id', function () {
    [$table, $orderId] = makeHeldTable($this->orgId, $this->branch->id);

    app(TableOccupancy::class)->releaseByOrderAfterPayment($orderId, needsCleaning: false);

    $row = DB::table('tables')->where('id', $table->id)->first();
    expect($row->status)->toBe('free')
        ->and($row->current_order_id)->toBeNull();
});

it('releaseByOrderAfterPayment đưa bàn về cleaning khi shop bật dọn bàn', function () {
    [$table, $orderId] = makeHeldTable($this->orgId, $this->branch->id);

    app(TableOccupancy::class)->releaseByOrderAfterPayment($orderId, needsCleaning: true);

    $row = DB::table('tables')->where('id', $table->id)->first();
    expect($row->status)->toBe('cleaning')
        ->and($row->current_order_id)->toBeNull();
});

it('chỉ nhả bàn của ĐÚNG đơn đó', function () {
    [$mine, $orderId] = makeHeldTable($this->orgId, $this->branch->id);
    [$other, $otherOrderId] = makeHeldTable($this->orgId, $this->branch->id);

    app(TableOccupancy::class)->releaseByOrderAfterPayment($orderId, needsCleaning: false);

    $row = DB::table('tables')->where('id', $other->id)->first();
    expect($row->current_order_id)->toBe($otherOrderId)
        ->and($row->status)->toBe('occupied');
});

// =============================================================================
//  GIỜ MỞ CỬA — cổng chỉ chuyển tiếp (#1160/#1167)
// =============================================================================

/**
 * `EloquentBranchOpeningWindow` không được có luật riêng: mọi câu trả lời phải
 * bằng đúng `BranchOpeningHours`, kể cả ca "chưa khai `weekly_hours` thì luôn
 * mở" — một adapter tự phán ở đây sẽ khoá cứng cửa hàng chưa cấu hình.
 */
it('BranchOpeningWindow trả lời y hệt BranchOpeningHours', function () {
    $branch = $this->branch;
    $branch->weekly_hours = [
        'mon' => [['open' => '09:00', 'close' => '17:00']],
        'tue' => [['open' => '09:00', 'close' => '17:00']],
        'wed' => [['open' => '09:00', 'close' => '17:00']],
        'thu' => [['open' => '09:00', 'close' => '17:00']],
        'fri' => [['open' => '09:00', 'close' => '17:00']],
        'sat' => [],
        'sun' => [],
    ];
    $branch->save();

    $port = app(BranchOpeningWindow::class);

    // Thứ Tư 12:00 và 03:00 giờ Tokyo — một trong ca, một ngoài ca.
    foreach (['2026-08-05 12:00:00', '2026-08-05 03:00:00'] as $wall) {
        $instant = CarbonImmutable::parse($wall, 'Asia/Tokyo');

        expect($port->isOpenAt($branch, $instant))
            ->toBe(BranchOpeningHours::isOpenAt($branch, $instant))
            ->and($port->closingAt($branch, $instant)?->toIso8601String())
            ->toBe(BranchOpeningHours::closingAt($branch, $instant)?->toIso8601String())
            ->and($port->nextOpeningAt($branch, $instant)?->toIso8601String())
            ->toBe(BranchOpeningHours::nextOpeningAt($branch, $instant)?->toIso8601String());
    }
});

it('chi nhánh chưa khai weekly_hours vẫn coi là ĐANG MỞ', function () {
    $instant = CarbonImmutable::parse('2026-08-05 03:00:00', 'Asia/Tokyo');

    expect(app(BranchOpeningWindow::class)->isOpenAt($this->branch, $instant))
        ->toBe(BranchOpeningHours::isOpenAt($this->branch, $instant));
});

/**
 * @return array{0: Table, 1: string}
 */
function makeHeldTable(string $orgId, string $branchId): array
{
    $zone = Zone::factory()->create(['organization_id' => $orgId, 'branch_id' => $branchId]);
    $table = Table::factory()->create([
        'organization_id' => $orgId,
        'branch_id' => $branchId,
        'zone_id' => $zone->id,
    ]);

    $orderId = (string) Str::uuid();
    DB::table('tables')->where('id', $table->id)->update([
        'current_order_id' => $orderId,
        'status' => 'occupied',
    ]);

    return [$table, $orderId];
}
