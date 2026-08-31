<?php

use App\Models\OrderPayment;
use App\Services\Payment\Observation\JsonKeyDebtThresholds;
use Illuminate\Support\Carbon;

/**
 * #2902 — ngưỡng phải biết KÊU và biết IM.
 *
 * Một ngưỡng chỉ biết im thì không phân biệt được với một ngưỡng đã tắt; một
 * ngưỡng chỉ biết kêu thì sẽ bị tắt. Cả hai chiều đều có ca ở đây.
 *
 * KHÔNG phủ: ca biên của `ROW_THRESHOLD` (20.000). Phủ nó cần seed 20k dòng,
 * quá đắt cho thứ nó mua được. Hệ quả đo được: đổi `>` thành `>=` ở vế số hàng
 * KHÔNG làm gì đỏ. Khai ra ở đây để không ai đọc màu xanh thành "đã ghim cả
 * ba ngưỡng" — hai ngưỡng kia có ca biên, ngưỡng này thì không.
 */
function seedJsonDebtPayment(array $overrides = []): void
{
    // Tên phải DUY NHẤT toàn cục: Pest nạp mọi file test vào MỘT tiến trình, và
    // `seedPayment()` đã có ở TillSessionExpireSchedulerTest. Trùng tên là fatal
    // lúc nạp, không phải một test đỏ — `TestHelperNameCollisionTest` bắt được
    // ngay lượt chạy đầu.
    // Dùng factory thay vì insert tay: bảng này có cột NOT NULL (payment_method_id,
    // customer_order_id) mà một insert tay sẽ phải đoán — và đoán sai thì test
    // đỏ vì lý do không liên quan tới thứ đang đo.
    OrderPayment::factory()->create(array_merge([
        'status' => 'succeeded',
        'paid_at' => Carbon::now()->subDay(),
        'branch_id' => 'br-1',
    ], $overrides));
}

it('#2902 IM khi chưa ngưỡng nào tới', function () {
    seedJsonDebtPayment();

    $report = app(JsonKeyDebtThresholds::class)->report();

    expect($report['actionable'])->toBeFalse();
    expect(collect($report['gates'])->pluck('condition_met')->all())->toBe([false, false, false]);
});

it('#2902 KÊU khi thấy lần hoàn tiền Stripe THẬT', function () {
    seedJsonDebtPayment(['metadata' => ['stripe_refund_id' => 're_1ABC']]);

    $report = app(JsonKeyDebtThresholds::class)->report();

    $gate = collect($report['gates'])->firstWhere('key', 'stripe_refund_seen');
    expect($gate['condition_met'])->toBeTrue();
    expect($report['actionable'])->toBeTrue();
});

it('#2902 hàng refunded do BACKFILL TAY không kích ngưỡng — đếm khoá, không đếm trạng thái', function () {
    // Bốn hàng `refunded` trên production lúc #2902 đều là backfill tay và
    // KHÔNG mang `stripe_refund_id`. Đếm theo trạng thái sẽ báo động giả.
    seedJsonDebtPayment(['status' => 'refunded', 'metadata' => ['note' => 'Khoi phuc tu backup']]);

    $gate = collect(app(JsonKeyDebtThresholds::class)->report()['gates'])
        ->firstWhere('key', 'stripe_refund_seen');

    expect($gate['condition_met'])->toBeFalse();
});

it('#2902 KÊU khi số chi nhánh ĐANG BÁN vượt ngưỡng', function () {
    foreach (range(1, JsonKeyDebtThresholds::BRANCH_THRESHOLD + 1) as $i) {
        seedJsonDebtPayment(['branch_id' => 'br-'.$i]);
    }

    $gate = collect(app(JsonKeyDebtThresholds::class)->report()['gates'])
        ->firstWhere('key', 'branches_selling');

    expect($gate['condition_met'])->toBeTrue();
});

it('#2902 chi nhánh NGOÀI cửa sổ không tính là đang bán', function () {
    foreach (range(1, JsonKeyDebtThresholds::BRANCH_THRESHOLD + 1) as $i) {
        seedJsonDebtPayment(['branch_id' => 'br-'.$i, 'paid_at' => Carbon::now()->subDays(400)]);
    }

    $gate = collect(app(JsonKeyDebtThresholds::class)->report()['gates'])
        ->firstWhere('key', 'branches_selling');

    expect($gate['condition_met'])->toBeFalse();
});

it('#2902 ĐÚNG ngưỡng chi nhánh thì IM — ngưỡng là "vượt", không phải "chạm"', function () {
    // Ca biên. Không có nó thì `>` và `>=` không phân biệt được, mà lệch một
    // đơn vị ở đây nghĩa là báo động ngay khi quán thứ tư mở — đúng lúc chưa
    // cần làm gì, và một ngưỡng kêu sớm thì bị tắt.
    foreach (range(1, JsonKeyDebtThresholds::BRANCH_THRESHOLD) as $i) {
        seedJsonDebtPayment(['branch_id' => 'br-'.$i]);
    }

    $gate = collect(app(JsonKeyDebtThresholds::class)->report()['gates'])
        ->firstWhere('key', 'branches_selling');

    expect($gate['condition_met'])->toBeFalse();
});
