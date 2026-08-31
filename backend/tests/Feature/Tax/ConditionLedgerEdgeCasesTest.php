<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderCondition;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * #2041 — biên của sổ `order_conditions`.
 *
 * `ServiceChargeConditionLedgerTest` giữ đường chính. File này giữ những chỗ mà
 * một cách cài đặt "gần đúng" vẫn xanh: chế độ giá đã gồm thuế, giảm giá bằng
 * đúng subtotal, phần dư của phép pro-rata, món bị huỷ, và mức 0%.
 *
 * ## Bất biến neo mọi ca — VÀ NÓ CÓ ĐIỀU KIỆN
 *
 * Giá CHƯA gồm thuế:  total_amount == subtotal + Σ(conditions.amount)
 * Giá ĐÃ gồm thuế:    total_amount == subtotal + Σ(conditions trừ `tax`)
 *
 * Vì ở chế độ 総額表示 thuế nằm BÊN TRONG subtotal rồi (`OrderPricingCalculator`:
 * *"Prices already include tax → do NOT add group taxes again"*), nên cộng thêm
 * dòng `tax` là đếm hai lần. Dòng `tax` vẫn phải tồn tại — hoá đơn bắt buộc nêu
 * thuế theo từng mức — nó chỉ không phải một khoản CỘNG THÊM.
 *
 * Ghi lại vì bản thân tôi đã phát biểu bất biến này như một quy tắc phổ quát
 * trong #2041 trước khi bộ test này bắt được (Nhật dùng 総額表示 rất phổ biến,
 * nên "phổ quát" ở đây là sai ở đúng thị trường chính).
 */
function ledgerOrder(array $attrs = []): CustomerOrder
{
    $order = CustomerOrder::factory()->create(array_merge([
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ], $attrs));

    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => 'round',
        ]
    );

    return $order;
}

function ledgerLine(CustomerOrder $order, float $unitPrice, ?float $rate, int $qty = 1, string $status = 'served'): CustomerOrderItem
{
    return CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => $qty,
        'unit_price' => $unitPrice,
        'topping_subtotal' => 0,
        'subtotal' => $qty * $unitPrice,
        'tax_rate' => $rate,
        'tax_amount' => 0,
        'status' => $status,
    ]);
}

it('ba property tiền là projection của sổ sau khi cột header bị xoá', function () {
    $order = ledgerOrder();
    $order->conditions()->createMany([
        ['type' => 'tax', 'source' => 'tax_type', 'label' => '10%', 'amount' => 100, 'currency_code' => 'JPY'],
        ['type' => 'discount', 'source' => 'manual', 'label' => 'Discount', 'amount' => -200, 'currency_code' => 'JPY'],
        ['type' => 'service_charge', 'source' => 'service_charge', 'label' => 'Service charge', 'amount' => 50, 'currency_code' => 'JPY'],
    ]);
    $order->unsetRelation('conditions');

    expect(Schema::hasColumns('customer_orders', ['discount_amount', 'service_charge', 'tax_amount']))->toBeFalse()
        ->and((float) $order->discount_amount)->toBe(200.0)
        ->and((float) $order->service_charge)->toBe(50.0)
        ->and((float) $order->tax_amount)->toBe(100.0);

    // Reverse witness: change only the ledger. A hidden scalar reader would
    // keep returning the old number and this assertion would fail.
    $order->conditions()->where('type', 'tax')->update(['amount' => 125]);
    $order->unsetRelation('conditions');
    expect((float) $order->tax_amount)->toBe(125.0);
});

function ledgerSettle(CustomerOrder $order): CustomerOrder
{
    $fresh = $order->fresh('items');
    app(CustomerOrderService::class)->refreshOrderTotals($fresh);

    return $fresh->fresh('items');
}

/** @return Collection<int, OrderCondition> */
function ledgerRows(CustomerOrder $order, ?string $type = null)
{
    $q = OrderCondition::query()
        ->where('conditionable_type', $order->getMorphClass())
        ->where('conditionable_id', $order->id);

    if ($type !== null) {
        $q->where('type', $type);
    }

    return $q->orderBy('type')->orderBy('rate')->get();
}

function ledgerSum(CustomerOrder $order, ?string $type = null): float
{
    return (float) ledgerRows($order, $type)->sum(fn ($r) => (float) $r->amount);
}

// ── Bất biến trung tâm, chạy trên nhiều hình dạng đơn ───────────────────────

dataset('hình dạng đơn', [
    'một mức, không giảm giá' => [[1000.0 => 10.0], 0, false],
    'hai mức, không giảm giá' => [[1000.0 => 8.0, 2000.0 => 10.0], 0, false],
    'hai mức, có giảm giá' => [[1000.0 => 8.0, 1000.0 => 10.0], 300, false],
    'ba mức, giảm giá lẻ' => [[700.0 => 8.0, 1100.0 => 10.0, 900.0 => 0.0], 333, false],
    'giá ĐÃ GỒM thuế' => [[1100.0 => 10.0, 1080.0 => 8.0], 0, true],
    'giá đã gồm thuế + giảm giá' => [[1100.0 => 10.0, 1080.0 => 8.0], 250, true],
]);

it('BẤT BIẾN: total_amount == subtotal + Σ(sổ)', function (array $lines, int $discount, bool $included) {
    $order = ledgerOrder(['discount_amount' => $discount, 'is_tax_included' => $included]);
    foreach ($lines as $price => $rate) {
        ledgerLine($order, (float) $price, $rate);
    }
    $order = ledgerSettle($order);

    $sum = $included
        ? ledgerSum($order) - ledgerSum($order, 'tax')   // 総額表示: thuế đã nằm trong subtotal
        : ledgerSum($order);

    expect((float) $order->subtotal + $sum)
        ->toBe((float) $order->total_amount, 'sổ không dựng lại được tổng đơn');
})->with('hình dạng đơn');

it('BẤT BIẾN: Σ(discount) == −discount_amount, đúng tuyệt đối', function (array $lines, int $discount, bool $included) {
    $order = ledgerOrder(['discount_amount' => $discount, 'is_tax_included' => $included]);
    foreach ($lines as $price => $rate) {
        ledgerLine($order, (float) $price, $rate);
    }
    $order = ledgerSettle($order);

    // Không phải xấp xỉ: phần dư của phép pro-rata được đặt vào mức CUỐI chính
    // để tổng khớp tuyệt đối. Một cài đặt làm tròn từng mức rồi cộng lại sẽ
    // lệch vài đơn vị ở đây và không lệch ở đâu khác.
    expect(ledgerSum($order, 'discount'))->toBe(-1.0 * (float) $order->discount_amount);
})->with('hình dạng đơn');

it('BẤT BIẾN: Σ(tax) == tax_amount', function (array $lines, int $discount, bool $included) {
    $order = ledgerOrder(['discount_amount' => $discount, 'is_tax_included' => $included]);
    foreach ($lines as $price => $rate) {
        ledgerLine($order, (float) $price, $rate);
    }
    $order = ledgerSettle($order);

    expect(ledgerSum($order, 'tax'))->toBe((float) $order->tax_amount);
})->with('hình dạng đơn');

// ── Chế độ giá đã gồm thuế ─────────────────────────────────────────────────

it('giá đã gồm thuế: taxable_base là nền CHƯA gồm thuế, không phải giá niêm yết', function () {
    // ¥1.100 đã gồm 10% ⇒ nền 1.000, thuế 100. Lưu 1.100 vào `taxable_base` là
    // sai một cách rất dễ lọt: tổng đơn vẫn đúng, chỉ tờ hoá đơn nói dối.
    $order = ledgerOrder(['is_tax_included' => true]);
    ledgerLine($order, 1100, 10);
    $order = ledgerSettle($order);

    $tax = ledgerRows($order, 'tax')->first();

    expect((float) $tax->taxable_base)->toBe(1000.0);
    expect((float) $tax->amount)->toBe(100.0);
    expect((float) $tax->taxable_base + (float) $tax->amount)->toBe(1100.0);
});

it('mỗi dòng thuế TỰ CÂN ở chính thuế suất nó khai', function (array $lines, int $discount, bool $included) {
    $order = ledgerOrder(['discount_amount' => $discount, 'is_tax_included' => $included]);
    foreach ($lines as $price => $rate) {
        ledgerLine($order, (float) $price, $rate);
    }
    $order = ledgerSettle($order);

    foreach (ledgerRows($order, 'tax') as $row) {
        $rate = (float) $row->rate;
        $want = (float) $row->taxable_base * $rate / 100.0;
        // Dung sai 1 đơn vị tiền: làm tròn một lần mỗi nhóm.
        expect((float) $row->amount)
            ->toBeGreaterThan($want - 1.0)
            ->toBeLessThan($want + 1.0, "mức {$rate}% không tự cân");
    }
})->with('hình dạng đơn');

// ── Biên của giảm giá ──────────────────────────────────────────────────────

it('giảm giá bằng ĐÚNG subtotal: nền về 0, sổ vẫn cân', function () {
    $order = ledgerOrder(['discount_amount' => 2000]);
    ledgerLine($order, 1000, 10);
    ledgerLine($order, 1000, 8);
    $order = ledgerSettle($order);

    expect((float) $order->tax_amount)->toBe(0.0);
    expect(ledgerSum($order, 'discount'))->toBe(-2000.0);
    expect((float) $order->subtotal + ledgerSum($order))->toBe((float) $order->total_amount);
});

it('giảm giá LỚN HƠN subtotal bị kẹp, và sổ ghi con số ĐÃ KẸP', function () {
    // Nếu sổ ghi số yêu cầu (5.000) còn cột ghi số đã kẹp (1.000) thì hai bên
    // lệch nhau và bất biến Σ vỡ — mà không có gì khác báo động.
    $order = ledgerOrder(['discount_amount' => 5000]);
    ledgerLine($order, 1000, 10);
    $order = ledgerSettle($order);

    // Sổ và property dẫn xuất đều nói số ĐÃ ÁP DỤNG (kẹp về subtotal). Ý định
    // thu ngân sống ở manual_discount_amount; ba scalar header đã bị xoá #2041.
    expect(ledgerSum($order, 'discount'))->toBe(-1000.0);
    expect((float) $order->discount_amount)->toBe(1000.0);
    expect((float) $order->manual_discount_amount)->toBe(5000.0);
    expect((float) $order->subtotal + ledgerSum($order))->toBe((float) $order->total_amount);
});

it('giảm giá trên đơn MỘT mức: cả phần dư vào đúng mức đó', function () {
    $order = ledgerOrder(['discount_amount' => 333]);
    ledgerLine($order, 1000, 10);
    $order = ledgerSettle($order);

    $rows = ledgerRows($order, 'discount');

    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]->amount)->toBe(-333.0);
    expect((float) $rows[0]->rate)->toBe(10.0);
});

it('mỗi dòng giảm giá theo mức mang meta.rate_group để truy ngược', function () {
    $order = ledgerOrder(['discount_amount' => 300]);
    ledgerLine($order, 1000, 8);
    ledgerLine($order, 1000, 10);
    $order = ledgerSettle($order);

    foreach (ledgerRows($order, 'discount') as $row) {
        if ($row->rate === null) {
            continue;
        }
        expect($row->meta['rate_group'] ?? null)->not->toBeNull();
    }
});

// ── Món bị huỷ / đơn rỗng ──────────────────────────────────────────────────

it('món VOIDED không vào nền chịu thuế', function () {
    $order = ledgerOrder();
    ledgerLine($order, 1000, 10);
    ledgerLine($order, 5000, 10, status: 'voided');
    $order = ledgerSettle($order);

    $tax = ledgerRows($order, 'tax')->first();

    expect((float) $tax->taxable_base)->toBe(1000.0);
});

it('mọi món bị huỷ: sổ không còn dòng thuế nào', function () {
    $order = ledgerOrder();
    ledgerLine($order, 1000, 10, status: 'voided');
    $order = ledgerSettle($order);

    expect(ledgerRows($order, 'tax'))->toHaveCount(0);
    expect((float) $order->subtotal + ledgerSum($order))->toBe((float) $order->total_amount);
});

// ── Nhãn + đơn vị tiền ─────────────────────────────────────────────────────

it('nhãn dòng thuế là "10%", không phải "10.00%"', function () {
    // Nhãn được ĐÓNG BĂNG vào sổ và in ra giấy. Máy trạm phải sinh cùng chuỗi
    // (`formatRateLabel` bên Go), nên định dạng ở đây là một hợp đồng.
    $order = ledgerOrder();
    ledgerLine($order, 1000, 10);
    ledgerLine($order, 1000, 8);
    $order = ledgerSettle($order);

    expect(ledgerRows($order, 'tax')->pluck('label')->all())->toBe(['8%', '10%']);
});

it('mọi dòng mang currency_code, không để rỗng', function () {
    $order = ledgerOrder(['discount_amount' => 200]);
    ledgerLine($order, 1000, 10);
    $order = ledgerSettle($order);

    foreach (ledgerRows($order) as $row) {
        expect($row->currency_code)->not->toBeEmpty("dòng {$row->type} thiếu currency_code");
    }
});

// ── Tái sinh + hoàn tiền ───────────────────────────────────────────────────

it('dòng refund SỐNG SÓT qua mọi lượt tính lại', function () {
    $order = ledgerOrder(['discount_amount' => 100]);
    ledgerLine($order, 1000, 10);
    $order = ledgerSettle($order);

    $order->conditions()->create([
        'type' => 'refund', 'source' => 'manual', 'label' => 'Refund',
        'rate' => 10, 'amount' => -500, 'currency_code' => 'JPY',
    ]);

    // `refund` ghi một SỰ KIỆN đã xảy ra, không phải giá trị dẫn xuất. Cuốn nó
    // vào lượt xoá-ghi-lại là xoá mất lịch sử hoàn tiền.
    ledgerSettle($order);
    ledgerSettle($order);

    expect(ledgerRows($order, 'refund'))->toHaveCount(1);
});

it('đổi giảm giá rồi tính lại: sổ theo kịp, không cộng dồn', function () {
    $order = ledgerOrder(['discount_amount' => 300]);
    ledgerLine($order, 1000, 8);
    ledgerLine($order, 1000, 10);
    $order = ledgerSettle($order);

    $order->update(['discount_amount' => 500]);
    $order = ledgerSettle($order);

    expect(ledgerSum($order, 'discount'))->toBe(-500.0);
    expect((float) $order->subtotal + ledgerSum($order))->toBe((float) $order->total_amount);
});

it('bỏ hẳn giảm giá: dòng cũ biến mất, không thành dòng 0', function () {
    $order = ledgerOrder(['discount_amount' => 300]);
    ledgerLine($order, 1000, 10);
    $order = ledgerSettle($order);
    expect(ledgerRows($order, 'discount'))->not->toHaveCount(0);

    $order->update(['discount_amount' => 0]);
    $order = ledgerSettle($order);

    expect(ledgerRows($order, 'discount'))->toHaveCount(0);
});

// ── Làm tròn theo ảnh chụp của đơn ─────────────────────────────────────────

it('chế độ làm tròn ẢNH CHỤP của đơn quyết định số thuế trong sổ', function (string $mode, float $expected) {
    // 1 món ¥1234 @10% ⇒ 123,4 — đúng cái ranh giới tách ceil khỏi floor.
    $order = ledgerOrder(['tax_rounding_mode' => $mode]);
    ledgerLine($order, 1234, 10);
    $order = ledgerSettle($order);

    expect(ledgerSum($order, 'tax'))->toBe($expected);
    expect(ledgerSum($order, 'tax'))->toBe((float) $order->tax_amount);
})->with([
    'ceil' => ['ceil', 124.0],
    'floor' => ['floor', 123.0],
]);

// ── Chia giảm giá THEO MỨC (lỗ do kiểm đột biến tìm ra) ────────────────────

it('đơn hai mức: giảm giá thành HAI dòng, mỗi dòng mang rate', function () {
    // Đây là chỗ mọi test khác đều mù. Nếu phép pro-rata hỏng và rơi về nhánh
    // dự phòng "một dòng rate=null mang cả khoản giảm", thì Σ(discount) VẪN
    // khớp `discount_amount`, tổng đơn VẪN đúng, và mọi bất biến ở trên vẫn
    // xanh — trong khi hoá đơn mất khả năng nói mức 8% được giảm bao nhiêu.
    // Kiểm đột biến (`$share = false`) tìm ra lỗ này.
    $order = ledgerOrder(['discount_amount' => 300]);
    ledgerLine($order, 1000, 8);
    ledgerLine($order, 1000, 10);
    $order = ledgerSettle($order);

    $rows = ledgerRows($order, 'discount');

    expect($rows)->toHaveCount(2);
    expect($rows->pluck('rate')->map(fn ($r) => (float) $r)->all())->toBe([8.0, 10.0]);
    expect($rows->every(fn ($r) => $r->rate !== null))->toBeTrue('dòng giảm giá thiếu rate');

    // Hai mức có subtotal bằng nhau ⇒ chia đôi.
    expect((float) $rows[0]->amount)->toBe(-150.0);
    expect((float) $rows[1]->amount)->toBe(-150.0);
});

it('ba mức, giảm giá không chia hết: phần dư dồn vào mức CUỐI', function () {
    // 300 trên ba mức bằng nhau = 100 mỗi mức, chia hết — nên dùng 100 để tạo
    // phần dư thật: 33,33 mỗi mức, và mức cuối phải gánh phần lẻ.
    $order = ledgerOrder(['discount_amount' => 100]);
    ledgerLine($order, 1000, 0);
    ledgerLine($order, 1000, 8);
    ledgerLine($order, 1000, 10);
    $order = ledgerSettle($order);

    $rows = ledgerRows($order, 'discount');

    expect($rows)->toHaveCount(3);
    expect(ledgerSum($order, 'discount'))->toBe(-100.0);

    // Không mức nào lệch quá một đơn vị tiền so với phần chia đều — phần dư
    // phải nằm gọn ở MỘT chỗ, không rải ra thành sai số khắp nơi.
    foreach ($rows as $r) {
        expect(abs((float) $r->amount))->toBeGreaterThanOrEqual(33.0)->toBeLessThanOrEqual(34.0);
    }
});

it('giảm giá dồn vào mức có doanh thu lớn hơn, theo tỉ lệ tiền món GỘP', function () {
    // Mẫu số là tiền món GỘP (chưa trừ giảm giá), không phải nền sau giảm.
    // Lấy nhầm nền sau giảm thì tỉ lệ khác tỉ lệ đã dùng lúc tính thuế, và
    // phần dư đi lạc — sai lệch nhỏ, chỉ lộ ra trên đơn nhiều mức.
    $order = ledgerOrder(['discount_amount' => 400]);
    ledgerLine($order, 1000, 8);
    ledgerLine($order, 3000, 10);
    $order = ledgerSettle($order);

    $rows = ledgerRows($order, 'discount')->keyBy(fn ($r) => (string) (float) $r->rate);

    expect((float) $rows['8']->amount)->toBe(-100.0);   // 1000/4000 × 400
    expect((float) $rows['10']->amount)->toBe(-300.0);  // 3000/4000 × 400
});
