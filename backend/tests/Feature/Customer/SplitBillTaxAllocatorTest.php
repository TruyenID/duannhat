<?php

/**
 * #2677 — bất biến của phiếu chia bill: Σ phiếu con == phiếu tổng, THEO TỪNG MỨC.
 *
 * Ruling ghi rõ đây là ĐIỀU KIỆN DỪNG: *"Một tờ hoá đơn có số thuế không cộng
 * lại đúng tệ hơn thiếu trường."* Nên bộ test này nặng về **chiều phải KÊU** —
 * một bộ phân bổ chỉ được kiểm ở ca đẹp sẽ im lặng đúng lúc nó sai.
 */

use App\Services\Customer\OrderPricingCalculator;
use App\Services\Customer\SplitBillTaxAllocator;

uses()->group('customer');

function splitAllocator(): SplitBillTaxAllocator
{
    return new SplitBillTaxAllocator(app(OrderPricingCalculator::class));
}

/** Σ theo từng mức, dùng để khẳng định bất biến từ phía TEST chứ không tin lớp. */
function sumByRate(array $bills, int $group, string $field): float
{
    return array_sum(array_map(fn ($b) => (float) $b[$group][$field], $bills));
}

it('chia ba phiếu KHÔNG đều: Σ khớp phiếu tổng từng mức, tới từng đồng', function () {
    // 10% và 8% cùng tồn tại (店内 + 軽減), và các phần chia lẻ để phép chia
    // KHÔNG tròn — ca mà làm tròn per-phiếu sẽ lệch.
    $byRate = [
        ['rate' => 0.10, 'taxable' => 3300.0, 'tax' => 330.0],
        ['rate' => 0.08, 'taxable' => 1000.0, 'tax' => 80.0],
    ];
    $shares = [1433.0, 1433.0, 1434.0];

    $bills = splitAllocator()->allocate($byRate, $shares, 1.0);

    expect($bills)->toHaveCount(3);
    expect(sumByRate($bills, 0, 'tax'))->toBe(330.0)
        ->and(sumByRate($bills, 0, 'taxable'))->toBe(3300.0)
        ->and(sumByRate($bills, 1, 'tax'))->toBe(80.0)
        ->and(sumByRate($bills, 1, 'taxable'))->toBe(1000.0);

    // JPY: không phiếu nào được mang phần lẻ dưới đồng ra giấy.
    foreach ($bills as $bill) {
        foreach ($bill as $row) {
            expect(fmod($row['tax'], 1.0))->toBe(0.0)
                ->and(fmod($row['taxable'], 1.0))->toBe(0.0);
        }
    }
});

it('phần dư phát trên CẢ TẬP, không phải từng phiếu — ba phiếu ¥100/3', function () {
    // ¥10 thuế chia ba: 3.33 mỗi phiếu. Làm tròn từng phiếu riêng cho ra
    // 3+3+3 = 9 (thiếu 1) hoặc 4+4+4 = 12 (thừa 2). Largest remainder trên cả
    // tập cho ra đúng 10.
    $bills = splitAllocator()->allocate(
        [['rate' => 0.10, 'taxable' => 100.0, 'tax' => 10.0]],
        [100.0, 100.0, 100.0],
        1.0,
    );

    expect(sumByRate($bills, 0, 'tax'))->toBe(10.0);
    $each = array_map(fn ($b) => $b[0]['tax'], $bills);
    sort($each);
    expect($each)->toBe([3.0, 3.0, 4.0]);
});

it('MẪU SỐ: một phiếu duy nhất phải bằng ĐÚNG phiếu tổng, không đổi một đồng', function () {
    // Ca "phải IM" của ruling. Đơn NGUYÊN đi qua đường mới không được lệch —
    // và không có bài này thì mọi bài trên vẫn xanh nếu bộ phân bổ âm thầm
    // làm tròn cả những ca không cần làm tròn.
    $byRate = [
        ['rate' => 0.10, 'taxable' => 3301.0, 'tax' => 331.0],
        ['rate' => 0.08, 'taxable' => 999.0, 'tax' => 79.0],
    ];

    $bills = splitAllocator()->allocate($byRate, [4710.0], 1.0);

    expect($bills[0])->toBe([
        ['rate' => 0.10, 'taxable' => 3301.0, 'tax' => 331.0],
        ['rate' => 0.08, 'taxable' => 999.0, 'tax' => 79.0],
    ]);
});

it('非課税 (0%) đi qua nguyên vẹn, không bị nhầm thành "chưa đóng dấu"', function () {
    // 0% là MỘT trong ba loại thuế hợp lệ, không phải "thiếu dữ liệu" — nhầm
    // hai thứ này là lỗi #1128 đã chặn ở đường pull.
    $bills = splitAllocator()->allocate(
        [['rate' => 0.0, 'taxable' => 500.0, 'tax' => 0.0]],
        [200.0, 300.0],
        1.0,
    );

    expect(sumByRate($bills, 0, 'taxable'))->toBe(500.0)
        ->and(sumByRate($bills, 0, 'tax'))->toBe(0.0)
        ->and($bills[0][0]['rate'])->toBe(0.0);
});

it('tiền tệ bước 0.01 (USD) — bất biến giữ ở mức xu', function () {
    // Ngưỡng đối soát phải theo BƯỚC của tiền tệ. Một epsilon cứng sẽ hoặc bỏ
    // lọt sai số thật ở JPY, hoặc kêu oan vì bụi dấu phẩy động ở USD.
    $bills = splitAllocator()->allocate(
        [['rate' => 0.10, 'taxable' => 10.0, 'tax' => 1.0]],
        [3.0, 3.0, 4.0],
        0.01,
    );

    expect(round(sumByRate($bills, 0, 'tax'), 2))->toBe(1.0)
        ->and(round(sumByRate($bills, 0, 'taxable'), 2))->toBe(10.0);
});

it('CHIỀU PHẢI KÊU: tổng phần chia bằng 0 mà còn thuế ⇒ NÉM, không chia đều', function () {
    // Chia đều ở đây là BỊA một quyết định phân bổ không dữ kiện nào đỡ, rồi
    // in nó ra như một sự thật. Ném là hành vi đúng.
    expect(fn () => splitAllocator()->allocate(
        [['rate' => 0.10, 'taxable' => 100.0, 'tax' => 10.0]],
        [0.0, 0.0],
        1.0,
    ))->toThrow(InvalidArgumentException::class);
});

it('tập phiếu RỖNG trả rỗng, không ném — không có gì để đối soát', function () {
    expect(splitAllocator()->allocate(
        [['rate' => 0.10, 'taxable' => 100.0, 'tax' => 10.0]],
        [],
        1.0,
    ))->toBe([]);
});

it('CHIỀU PHẢI KÊU: cổng bất biến chạy trên MỌI lượt, không chỉ trong test', function () {
    // Bài này ghim rằng `assertReconciles` thật sự được gọi từ `allocate()`.
    // Không có nó, cổng có thể bị gỡ mà mọi bài trên vẫn xanh — chúng đo KẾT
    // QUẢ, không đo việc cổng có tồn tại hay không.
    //
    // `OrderPricingCalculator` là `final`, nên seam nằm ở chính lớp đang test:
    // `allocateGroup()` được ghi đè để trả về một phân bổ lệch ĐÚNG MỘT ĐỒNG.
    $liar = new class(app(OrderPricingCalculator::class)) extends SplitBillTaxAllocator
    {
        protected function allocateGroup(array $ideals, float $groupTotal, float $step): array
        {
            $out = array_fill(0, count($ideals), 0.0);
            $out[0] = $groupTotal - 1.0;

            return $out;
        }
    };

    expect(fn () => $liar->allocate(
        [['rate' => 0.10, 'taxable' => 100.0, 'tax' => 10.0]],
        [50.0, 50.0],
        1.0,
    ))->toThrow(RuntimeException::class, 'KHÔNG khớp');
});
