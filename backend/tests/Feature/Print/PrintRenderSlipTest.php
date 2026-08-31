<?php

declare(strict_types=1);

use App\Services\Print\Renderer\PrintRenderSlip;

/*
 * plan-053 T5.1d (#1923) — VO `slip` của họ bill.
 *
 * `splitModeKind()` lái nhánh của `emitBillGrandTotal`, nên phân loại sai ở đây
 * làm **dòng tổng tiền in sai loại** — thứ khách đọc đầu tiên trên phiếu.
 */

it('#1923 splitModeKind phân loại đúng ba nhánh + rỗng', function (string $mode, int $count, string $want) {
    expect((new PrintRenderSlip(splitCount: $count, splitMode: $mode))->splitModeKind())->toBe($want);
})->with([
    ['by_items', 0, 'by_items'],
    ['by_amount', 0, 'by_amount'],
    // #2860 — `equal` KHÔNG còn được nhận ở đây. Chuẩn hoá xảy ra ở biên vào
    // và migration đã viết lại dữ liệu đã lưu, nên renderer chỉ thấy canonical.
    // Nhận thêm một tên ở tầng này là đẻ lại từ vựng thứ hai ở đúng chỗ không
    // ai coi là nơi định nghĩa từ vựng.
    ['even', 0, 'even'],
    ['', 1, ''],
    ['', 0, ''],
]);

it('#1923 splitMode rỗng + nhiều người = chia đều', function () {
    // Suy đoán CÓ CHỦ ĐÍCH: đơn cũ không ghi `splitMode`. Bỏ nhánh này thì
    // chúng in như phiếu thường — mất hẳn dòng "phần của người thứ N".
    expect((new PrintRenderSlip(splitCount: 3))->splitModeKind())->toBe('even');
});

it('#1923 slip mang đủ 13 trường của PaymentSlipInfo', function () {
    // Grep `data.Slip.X` trong file emitter chỉ ra 7 — đó là số của những
    // emitter đã đọc, không phải của struct. Sáu trường còn lại được đọc bởi
    // các emitter thanh toán. Đo theo ĐỊNH NGHĨA KIỂU, không theo cái vừa đọc.
    $props = array_map(
        static fn (ReflectionProperty $p): string => $p->getName(),
        (new ReflectionClass(PrintRenderSlip::class))->getProperties(),
    );

    expect($props)->toHaveCount(13)
        ->toContain('paymentMethod')->toContain('tendered')->toContain('change')
        ->toContain('remaining')->toContain('splitMode')->toContain('reprintNumber');
});

it('#1923 fromArray ánh xạ đủ, số về int và chuỗi về string', function () {
    $s = PrintRenderSlip::fromArray([
        'payment_method' => 'cash',
        'amount_paid' => '1500',
        'split_index' => 2,
        'split_count' => 3,
        'tendered' => 2000,
        'change' => 500,
        'customer_name' => 'Tanaka',
        'split_mode' => 'even',
        'order_gross_total' => 4500,
        'reprint_number' => 2,
    ]);

    expect($s->paymentMethod)->toBe('cash')
        ->and($s->amountPaid)->toBe(1500)
        ->and($s->splitCount)->toBe(3)
        ->and($s->tendered)->toBe(2000)
        ->and($s->change)->toBe(500)
        ->and($s->customerName)->toBe('Tanaka')
        ->and($s->reprintNumber)->toBe(2)
        ->and($s->splitModeKind())->toBe('even');
});
