<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Services\Payment\Contracts\BranchPaymentTotals;
use Illuminate\Support\Str;

/**
 * #1622 — cổng Payments công bố tiền đã thu (đã RÒNG) cho báo cáo doanh thu POS.
 *
 * Ba luật dưới đây từng chỉ sống trong comment của `PosRevenueService`, và cả ba
 * hỏng theo kiểu **im lặng** — báo cáo vẫn ra một con số trông hợp lý:
 *
 *   1. hoàn tiền là dòng ÂM ⇒ cộng vào là trừ ra;
 *   2. #1123 — thắng tranh chấp ghi dòng DƯƠNG `dispute_kind=reinstatement`
 *      không có `refund_of_id`; bỏ sót thì vụ thắng bị trừ mãi;
 *   3. #1125 — dòng `settles_payment_id` là bút toán tất toán, cộng vào là
 *      **đếm hai lần**.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->totals = app(BranchPaymentTotals::class);
    $this->from = now()->subDay();
    $this->to = now()->addDay();

    $this->order = CustomerOrder::create([
        'order_code' => 'R-'.Str::random(6),
        'order_type' => 'spot',
        'status' => 'closed',
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);

    $this->method = PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'code' => 'cash',
    ]);

    $this->payment = function (array $attributes): OrderPayment {
        return OrderPayment::create(array_merge([
            // `payment_code` NOT NULL — mã chứng từ thu tiền, sinh theo dòng.
            'payment_code' => 'P-'.Str::random(8),
            'customer_order_id' => $this->order->id,
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'payment_method_id' => $this->method->id,
            'status' => 'succeeded',
            'paid_at' => now(),
        ], $attributes));
    };
});

it('có binding thật, không phải interface rỗng', function () {
    expect($this->totals)->toBeInstanceOf(BranchPaymentTotals::class);
});

it('không có dòng đảo tiền → 0', function () {
    ($this->payment)(['amount' => 1000]);

    expect($this->totals->reversalTotal((string) $this->branch->id, $this->orgId, $this->from, $this->to))
        ->toBe(0.0);
});

it('hoàn tiền là dòng ÂM và được cộng vào (tức trừ ra)', function () {
    $sale = ($this->payment)(['amount' => 1000, 'status' => 'refunded']);
    ($this->payment)(['amount' => -400, 'refund_of_id' => $sale->id]);

    expect($this->totals->reversalTotal((string) $this->branch->id, $this->orgId, $this->from, $this->to))
        ->toBe(-400.0);
});

/**
 * #1123 — THẮNG tranh chấp: dòng dương, KHÔNG có `refund_of_id`. Bỏ sót nhánh
 * `dispute_kind=reinstatement` thì vụ thắng vẫn bị trừ mãi, và KPI báo 0 trong
 * khi bảng theo phương thức nói tiền đã về.
 */
it('#1123 — thắng tranh chấp cộng lại tiền', function () {
    $sale = ($this->payment)(['amount' => 1000, 'status' => 'refunded']);
    ($this->payment)(['amount' => -1000, 'refund_of_id' => $sale->id]);
    ($this->payment)(['amount' => 1000, 'metadata' => ['dispute_kind' => 'reinstatement']]);

    expect($this->totals->reversalTotal((string) $this->branch->id, $this->orgId, $this->from, $this->to))
        ->toBe(0.0);
});

/** #1125 — bút toán tất toán không được tính, nếu không là đếm hai lần. */
it('#1125 — bỏ qua dòng settles_payment_id', function () {
    $sale = ($this->payment)(['amount' => 1000, 'status' => 'refunded']);
    ($this->payment)([
        'amount' => -500,
        'refund_of_id' => $sale->id,
        'metadata' => ['settles_payment_id' => (string) Str::uuid()],
    ]);

    expect($this->totals->reversalTotal((string) $this->branch->id, $this->orgId, $this->from, $this->to))
        ->toBe(0.0);
});

it('theo phương thức: cộng CẢ succeeded lẫn refunded để ra số ròng', function () {
    $sale = ($this->payment)(['amount' => 1000, 'status' => 'refunded']);
    ($this->payment)(['amount' => -400, 'refund_of_id' => $sale->id]);

    $rows = $this->totals->netByPaymentMethod((string) $this->branch->id, $this->orgId, $this->from, $this->to);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['amount'])->toBe(600)
        ->and($rows[0]['code'])->toBe('cash');
});

it('KHÔNG lẫn chi nhánh khác', function () {
    $other = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    ($this->payment)(['amount' => 1000, 'branch_id' => $other->id]);

    expect($this->totals->netByPaymentMethod((string) $this->branch->id, $this->orgId, $this->from, $this->to))
        ->toBe([]);
});
