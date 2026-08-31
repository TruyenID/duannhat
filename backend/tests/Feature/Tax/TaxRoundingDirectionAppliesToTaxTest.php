<?php

use App\Services\Customer\OrderPricingCalculator;

/**
 * #2480 — hướng làm tròn phải áp lên SỐ THUẾ, ở cả hai chế độ.
 *
 * ## Vì sao lỗi này sống được lâu
 *
 * Bản trước tính 内税 là `gross − round(gross / (1 + r))`: hướng làm tròn áp lên
 * phần NỀN, thuế là phần dư. Nó **tự nhất quán** — cột số vẫn cộng ra đúng tổng,
 * vì ở 内税 `totalAmount = round(Σ gross + phí phục vụ)` không dùng tới con số
 * thuế. Nên mọi phép kiểm "cột có cộng ra tổng không" đều xanh.
 *
 * Và toàn bộ test hiện có chạy ở mode mặc định, nơi hai công thức cho kết quả
 * **giống hệt** — quét 40.000 tổ hợp (8% và 10% × gross 1…20.000): `half_up`
 * lệch **0 ca**. Nên không có bài nào đỏ để chỉ ra vấn đề.
 *
 * Điều đó cũng là lý do bản vá an toàn: 16/17 chi nhánh production đang ở
 * `half_up`/`round` và không đổi một đồng nào. Đúng một chi nhánh dùng `floor`.
 *
 * ## Hợp đồng bị vi phạm
 *
 * Nhãn nói thẳng nó làm gì — `settings.order.tax_rounding_mode_hint`:
 * *"Which direction **tax amounts** are rounded on each order."* Và 消費税 của
 * Nhật cũng làm tròn số thuế (税込価格 × 10/110 rồi 端数処理), không làm tròn giá
 * chưa thuế.
 */
uses()->group('tax');

beforeEach(function () {
    $this->calc = app(OrderPricingCalculator::class);
    // 内税, bước làm tròn ¥1 — cấu hình của mọi chi nhánh production.
    $this->taxOn = fn (float $gross, string $mode, float $rate = 10.0) => $this->calc
        ->groupTaxFor($gross, $rate, true, 1.0, $mode);
});

it('làm tròn XUỐNG cho thuế THẤP hơn — trước đây nó cho CAO hơn', function () {
    // ¥1.005 @10% 内税: thuế chính xác = 1005 × 10/110 = 91,36
    expect(($this->taxOn)(1005.0, 'floor'))->toBe(91.0)
        ->and(($this->taxOn)(1005.0, 'ceil'))->toBe(92.0);

    // Bất biến thật sự cần giữ, độc lập với con số cụ thể: xuống ≤ nửa ≤ lên.
    $down = ($this->taxOn)(1005.0, 'floor');
    $half = ($this->taxOn)(1005.0, 'round');
    $up = ($this->taxOn)(1005.0, 'ceil');
    expect($down)->toBeLessThanOrEqual($half)
        ->and($half)->toBeLessThanOrEqual($up);
});

it('ca biên đã từng khai TOÀN BỘ giá là thuế', function () {
    // ¥1 @8% 内税 với `floor`. Công thức cũ: `1 − floor(1/1,08) = 1 − 0 = 1`.
    // Tức một món ¥1 khai ¥1 tiền thuế — thuế suất 100%.
    // Đúng: 1 × 8/108 = 0,074 → làm tròn xuống = 0.
    expect(($this->taxOn)(1.0, 'floor', 8.0))->toBe(0.0);

    // Cùng lỗi ở mọi giá nhỏ hơn bước làm tròn chia cho thuế suất.
    foreach ([1.0, 2.0, 5.0, 10.0] as $gross) {
        expect(($this->taxOn)($gross, 'floor', 8.0))
            ->toBeLessThan($gross, "gross={$gross}: thuế không thể bằng cả giá");
    }
});

it('chế độ mặc định KHÔNG đổi một đồng nào — đây là điều khiến bản vá an toàn', function () {
    // Quét lại đúng phép đo đã dùng để quyết định: nếu `half_up` xê dịch dù chỉ
    // một ca, bản vá này chạm vào 16/17 chi nhánh production thay vì một.
    foreach ([8.0, 10.0] as $rate) {
        for ($gross = 1; $gross <= 3000; $gross++) {
            $cu = $gross - round($gross / (1 + $rate / 100.0));
            expect(($this->taxOn)((float) $gross, 'round', $rate))
                ->toBe((float) $cu, "gross={$gross}@{$rate}%: half_up phải bất động");
        }
    }
});

it('税抜 không đổi — ở đó hướng vốn đã áp đúng lên thuế', function () {
    $net = fn (string $mode) => $this->calc->groupTaxFor(1005.0, 10.0, false, 1.0, $mode);

    expect($net('floor'))->toBe(100.0)   // 100,5 → xuống
        ->and($net('ceil'))->toBe(101.0) // 100,5 → lên
        ->and($net('round'))->toBe(101.0);
});

it('phí phục vụ đi cùng một hình dạng, không phải hình dạng cũ', function () {
    // Trước đây `serviceChargeTax` có đúng công thức đảo ngược. Để nó lại là
    // giữ một nửa lỗi: dòng phí phục vụ sẽ làm tròn ngược chiều dòng món trên
    // cùng một hoá đơn.
    $r = $this->calc->priceGroups(
        rateSubtotals: ['10' => 1005.0],
        discount: 0.0,
        serviceChargeRate: 10.0,
        serviceChargeTaxRate: 10.0,
        pricesIncludeTax: true,
        step: 1.0,
        taxStep: 1.0,
        taxMode: 'floor',
    );

    // phí = round(1005 × 10%) = 101 (gross, đã gồm thuế)
    // thuế trong phí = floor(101 × 10/110) = floor(9,18) = 9
    expect($r->serviceCharge)->toBe(101.0)
        ->and($r->serviceChargeTax)->toBe(9.0);
});

it('tổng tiền khách trả KHÔNG đổi — chỉ phần ghi nhận là thuế mới đổi', function () {
    // Bất biến quan trọng nhất của bản vá. Ở 内税 tổng là `Σ gross + phí`, không
    // dùng tới con số thuế, nên đổi công thức thuế không được chạm vào nó.
    $total = function (string $mode): float {
        return $this->calc->priceGroups(
            rateSubtotals: ['10' => 1005.0],
            discount: 0.0,
            serviceChargeRate: 0.0,
            serviceChargeTaxRate: 0.0,
            pricesIncludeTax: true,
            step: 1.0,
            taxStep: 1.0,
            taxMode: $mode,
        )->totalAmount;
    };

    expect($total('floor'))->toBe($total('ceil'))
        ->and($total('floor'))->toBe($total('round'))
        ->and($total('floor'))->toBe(1005.0);
});

it('KHỚP TỪNG BIT với bản Go của máy trạm', function () {
    // `workstation/internal/service/pricing.go::GroupTaxFor` là bản port. Lệch
    // một yên ở đây nổi lên thành LỖI XÁC MINH trên một đơn offline vốn đúng:
    // Cloud re-price từ ảnh chụp bất biến trước khi tin tiền (#1092).
    //
    // Đọc thẳng nguồn Go thay vì chép con số, để đổi một bên mà quên bên kia là đỏ.
    $go = file_get_contents(base_path('../workstation/internal/service/pricing.go'));
    $body = explode('func GroupTaxFor', $go)[1] ?? '';
    $body = substr($body, 0, (int) strpos($body, "\n}"));

    expect($body)->toContain('roundToStep(netGroup*rate/(100.0+rate), taxStep, taxMode)')
        ->and($body)->not->toContain('netGroup - roundToStep');
});
