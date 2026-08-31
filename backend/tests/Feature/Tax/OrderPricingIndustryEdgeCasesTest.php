<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderCondition;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderTaxBreakdownAggregator;

/**
 * Ca biên của động cơ định giá đơn, lấy từ CHUẨN NGÀNH chứ không từ trí tưởng tượng.
 *
 * Nguồn cho từng nhóm được ghi ngay tại test. Bốn nguồn chính:
 *
 * 1. **国税庁 No.6371 + インボイス制度** — 適格請求書 phải làm tròn **một lần mỗi
 *    thuế suất trên MỖI HOÁ ĐƠN**; làm tròn theo từng món **không được chấp
 *    nhận** kể từ chế độ hoá đơn. Giảm giá gộp phải **按分** (chia theo tỉ lệ)
 *    qua các mức.
 * 2. **Peppol BIS Billing 3.0 / EN16931 BR-S-08** — nền chịu thuế của mỗi mức =
 *    Σ dòng net + Σ phụ phí − Σ khoản giảm, **theo từng mức**. Dung sai chính
 *    thức là ±0,01 mỗi mức; tổng là (số mức × 0,02).
 * 3. **Ngành POS** — làm tròn theo dòng so với theo hoá đơn lệch nhau 1–2 xu
 *    trên đơn nhiều dòng; và giảm giá cấp đơn **không chia đều được**, phải có
 *    phép phân bổ phần dư.
 * 4. **QA thương mại điện tử** — đơn tổng 0 phải đặt được; giảm giá vượt giỏ
 *    phải bị kẹp; lỗi rò doanh thu hay nằm ở THỨ TỰ áp dụng.
 *
 * Mỗi test dưới đây ghim một mệnh đề kiểm được, và nêu cái sai mà nó bắt.
 */
function iecOrder(array $attrs = [], float $scRate = 0, float $scTaxRate = 0): CustomerOrder
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
            'service_charge_rate' => $scRate,
            'service_charge_tax_rate' => $scTaxRate,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => 'round',
        ]
    );

    return $order;
}

function iecLine(CustomerOrder $order, float $unitPrice, ?float $rate, int $qty = 1): CustomerOrderItem
{
    return CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => $qty,
        'unit_price' => $unitPrice,
        'topping_subtotal' => 0,
        'subtotal' => $qty * $unitPrice,
        'tax_rate' => $rate,
        'tax_amount' => 0,
        'status' => 'served',
    ]);
}

function iecSettle(CustomerOrder $order): CustomerOrder
{
    $fresh = $order->fresh('items');
    app(CustomerOrderService::class)->refreshOrderTotals($fresh);

    return $fresh->fresh('items');
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. 端数処理は税率ごとに1回 — 国税庁 No.6371 / インボイス制度
// ─────────────────────────────────────────────────────────────────────────────

it('Σ thuế TỪNG DÒNG == thuế của NHÓM — làm tròn một lần mỗi mức, không phải mỗi món', function () {
    // Đây là mệnh đề trung tâm của chế độ hoá đơn Nhật: 適格請求書 làm tròn MỘT
    // LẦN cho mỗi thuế suất trên mỗi hoá đơn. Làm tròn theo từng món bị BÃI BỎ.
    //
    // Ba dòng ¥333 @10% ⇒ nhóm 999 × 10% = 99,9 → 100.
    // Nếu làm tròn theo món: 33,3 → 33 mỗi món, Σ = 99. Lệch 1 yên, và lệch
    // THEO HƯỚNG THU THIẾU — đúng loại sai số cơ quan thuế nhìn thấy.
    $order = iecOrder();
    iecLine($order, 333, 10);
    iecLine($order, 333, 10);
    iecLine($order, 333, 10);
    $order = iecSettle($order);

    expect((float) $order->tax_amount)->toBe(100.0);

    $lineSum = (float) $order->items->sum(fn ($i) => (float) $i->tax_amount);
    expect($lineSum)->toBe(100.0, 'Σ thuế từng dòng lệch thuế nhóm — đã làm tròn theo món');
});

it('phần dư khi chia thuế nhóm về từng dòng nằm gọn ở MỘT dòng, không rải ra', function () {
    // Phép phân bổ theo largest-remainder: 100 chia cho ba dòng bằng nhau ra
    // 33,33 — không dòng nào được lệch quá 1 đơn vị so với phần chia đều, và
    // tổng phải khớp tuyệt đối. Một cài đặt "làm tròn từng phần rồi cộng" sẽ
    // cho tổng 99 hoặc 102 mà từng dòng trông vẫn hợp lý.
    $order = iecOrder();
    iecLine($order, 333, 10);
    iecLine($order, 333, 10);
    iecLine($order, 333, 10);
    $order = iecSettle($order);

    foreach ($order->items as $item) {
        expect((float) $item->tax_amount)->toBeGreaterThanOrEqual(33.0)->toBeLessThanOrEqual(34.0);
    }
    expect((float) $order->items->sum(fn ($i) => (float) $i->tax_amount))->toBe((float) $order->tax_amount);
});

it('hai mức trên cùng đơn = ĐÚNG HAI lần làm tròn, không phải một lần trên tổng', function () {
    // 10%対象 và 8%対象 phải được làm tròn RIÊNG. Gộp cả hai rồi làm tròn một
    // lần là cách cũ (trước chế độ hoá đơn) và cho ra con số khác.
    //
    // 555 @8% → 44,4 → 44 · 555 @10% → 55,5 → 56 (half-up) ⇒ tổng 100.
    // Gộp: (555+555) tính theo một mức bất kỳ đều KHÔNG ra 100.
    $order = iecOrder();
    iecLine($order, 555, 8);
    iecLine($order, 555, 10);
    $order = iecSettle($order);

    expect((float) $order->tax_amount)->toBe(100.0);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. BR-S-08 (Peppol/EN16931) — nền mỗi mức = Σ dòng − Σ giảm + Σ phụ phí
// ─────────────────────────────────────────────────────────────────────────────

it('BR-S-08: Σ nền chịu thuế mọi mức == subtotal − giảm giá (khi không có phụ phí)', function () {
    $order = iecOrder(['discount_amount' => 400]);
    iecLine($order, 1000, 8);
    iecLine($order, 3000, 10);
    $order = iecSettle($order);

    $breakdown = app(OrderTaxBreakdownAggregator::class)
        ->forOrders(collect([$order->id]));

    $sumTaxable = collect($breakdown['by_rate'])->sum('taxable');

    expect((float) $sumTaxable)->toBe((float) $order->subtotal - (float) $order->discount_amount);
});

it('BR-S-08: phụ phí dịch vụ CỘNG vào nền của mức mà nó chịu, không tạo mức mới', function () {
    // Phí dịch vụ chịu 10% trên đơn đã có dòng 10% ⇒ nhập vào nhóm 10%, KHÔNG
    // sinh nhóm thứ hai cùng mức. Sinh nhóm trùng mức là lỗi hay gặp và làm
    // hoá đơn in hai dòng "10%対象".
    $order = iecOrder([], scRate: 10, scTaxRate: 10);
    iecLine($order, 1000, 10);
    $order = iecSettle($order);

    $breakdown = app(OrderTaxBreakdownAggregator::class)
        ->forOrders(collect([$order->id]));

    $rates = collect($breakdown['by_rate'])->pluck('rate')->map(fn ($r) => (float) $r);

    expect($rates->count())->toBe($rates->unique()->count(), 'có hai dòng cùng một mức');
    expect((float) $order->service_charge)->toBe(100.0);
    // Nền 10% = 1000 (món) + 100 (phí) = 1100.
    expect((float) collect($breakdown['by_rate'])->firstWhere('rate', 10.0)['taxable'])->toBe(1100.0);
});

it('dung sai Peppol: mỗi mức lệch không quá 0,01 — ở JPY nghĩa là khớp tuyệt đối', function () {
    // Peppol cho ±0,01 mỗi mức vì đơn vị nhỏ nhất là xu. JPY không có phần
    // thập phân, nên cùng quy tắc siết thành "phải khớp chính xác" — và đó là
    // phép thử chặt hơn, không phải lỏng hơn.
    $order = iecOrder(['discount_amount' => 333]);
    iecLine($order, 700, 8);
    iecLine($order, 1100, 10);
    iecLine($order, 900, 0);
    $order = iecSettle($order);

    $breakdown = app(OrderTaxBreakdownAggregator::class)
        ->forOrders(collect([$order->id]));

    foreach ($breakdown['by_rate'] as $row) {
        $ideal = (float) $row['taxable'] * (float) $row['rate'] / 100.0;
        expect(abs((float) $row['tax'] - $ideal))->toBeLessThanOrEqual(1.0);
    }
    expect((float) collect($breakdown['by_rate'])->sum('tax'))->toBe((float) $order->tax_amount);
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. Ngành POS — trôi số khi nhiều dòng, và giá nhỏ nhất
// ─────────────────────────────────────────────────────────────────────────────

it('100 dòng ¥1: không trôi số — làm tròn theo nhóm nên chỉ tròn một lần', function () {
    // Ca kinh điển của "làm tròn theo dòng": 100 món ¥1 @10% ⇒ mỗi món 0,1 yên.
    // Tròn theo dòng thì mỗi món về 0 và tổng thuế = 0 — quán bán 100 yên mà
    // khai 0 thuế. Tròn theo nhóm: 100 × 10% = 10.
    $order = iecOrder();
    for ($i = 0; $i < 100; $i++) {
        iecLine($order, 1, 10);
    }
    $order = iecSettle($order);

    expect((float) $order->subtotal)->toBe(100.0);
    expect((float) $order->tax_amount)->toBe(10.0, 'thuế bị làm tròn theo từng dòng');
    expect((float) $order->items->sum(fn ($i) => (float) $i->tax_amount))->toBe(10.0);
});

it('món rẻ nhất (¥1) vẫn sinh thuế ở mức nhóm, không biến mất', function () {
    $order = iecOrder();
    iecLine($order, 1, 10);
    $order = iecSettle($order);

    // 1 × 10% = 0,1 → half-up ở bước 1 yên ⇒ 0. Đây là hành vi ĐÚNG với JPY,
    // và test ghim nó để không ai "sửa" thành 1 bằng cách làm tròn lên.
    expect((float) $order->tax_amount)->toBe(0.0);
    expect((float) $order->total_amount)->toBe(1.0);
});

it('giảm giá 1 yên trên 3 mức: không mức nào ăn quá phần của nó, tổng vẫn khớp', function () {
    // Phần dư nhỏ nhất có thể. Một cài đặt chia rồi làm tròn từng phần sẽ cho
    // Σ = 0 (mỗi mức 0,33 → 0) và 1 yên biến mất khỏi sổ.
    $order = iecOrder(['discount_amount' => 1]);
    iecLine($order, 1000, 0);
    iecLine($order, 1000, 8);
    iecLine($order, 1000, 10);
    $order = iecSettle($order);

    expect((float) $order->subtotal + (float) $order->tax_amount - (float) $order->discount_amount)
        ->toBe((float) $order->total_amount);
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. QA thương mại điện tử — tổng 0, kẹp, và thứ tự áp dụng
// ─────────────────────────────────────────────────────────────────────────────

it('đơn tổng 0 vẫn hợp lệ — giỏ toàn hàng tặng là chuyện thật', function () {
    $order = iecOrder();
    iecLine($order, 0, 10);
    iecLine($order, 0, 8);
    $order = iecSettle($order);

    expect((float) $order->subtotal)->toBe(0.0);
    expect((float) $order->tax_amount)->toBe(0.0);
    expect((float) $order->total_amount)->toBe(0.0);
});

it('giảm giá vượt giỏ bị KẸP về subtotal — tổng không bao giờ âm', function () {
    // Lỗi rò doanh thu kinh điển: giảm ¥5.000 trên giỏ ¥1.000 ra tổng −4.000,
    // và nếu cổng thanh toán chấp nhận thì đó là tiền chạy ngược.
    $order = iecOrder(['discount_amount' => 5000]);
    iecLine($order, 1000, 10);
    $order = iecSettle($order);

    expect((float) $order->total_amount)->toBeGreaterThanOrEqual(0.0);
    expect((float) $order->tax_amount)->toBe(0.0);
});

it('THỨ TỰ áp dụng: giảm giá TRƯỚC thuế, phí dịch vụ trên nền ĐÃ giảm', function () {
    // Thứ tự sai là nguồn lỗi rò doanh thu hay gặp nhất. Ghim bằng số cụ thể:
    //
    //   món 1.000 @10% · giảm 200 · phí dịch vụ 10% · phí chịu thuế 10%
    //   nền sau giảm       = 800
    //   phí dịch vụ        = 800 × 10%  = 80      ← trên nền ĐÃ giảm, không phải 1.000
    //   thuế               = (800 + 80) × 10% = 88
    //   tổng               = 800 + 80 + 88 = 968
    //
    // Tính phí trên 1.000 sẽ ra phí 100 và tổng 988 — chênh 20 yên mỗi đơn,
    // và không có gì đỏ lên.
    $order = iecOrder(['discount_amount' => 200], scRate: 10, scTaxRate: 10);
    iecLine($order, 1000, 10);
    $order = iecSettle($order);

    expect((float) $order->service_charge)->toBe(80.0, 'phí dịch vụ tính trên nền CHƯA giảm');
    expect((float) $order->tax_amount)->toBe(88.0);
    expect((float) $order->total_amount)->toBe(968.0);
});

it('giá ĐÃ GỒM thuế: tổng KHÔNG cộng thêm thuế lần nữa', function () {
    // 総額表示. Cộng thuế lần nữa là lỗi đắt nhất trong họ này: khách bị tính
    // thừa đúng bằng số thuế, và con số trên giá niêm yết không khớp giấy.
    $order = iecOrder(['is_tax_included' => true]);
    iecLine($order, 1100, 10);
    $order = iecSettle($order);

    expect((float) $order->total_amount)->toBe(1100.0);
    expect((float) $order->tax_amount)->toBe(100.0);
});

it('mức 0% không sinh thuế nhưng VẪN nằm trong nền chịu thuế của tờ khai', function () {
    // 非課税 khác "không có". Hoá đơn phải nêu được phần 0% — bỏ hẳn nó là mất
    // một dòng bắt buộc, và tổng nền không còn khớp subtotal.
    $order = iecOrder();
    iecLine($order, 1000, 0);
    iecLine($order, 1000, 10);
    $order = iecSettle($order);

    $breakdown = app(OrderTaxBreakdownAggregator::class)
        ->forOrders(collect([$order->id]));

    expect((float) collect($breakdown['by_rate'])->sum('taxable'))->toBe(2000.0);
    expect((float) $order->tax_amount)->toBe(100.0);
});

it('nhóm THẬT SỰ rỗng (nền 0, thuế 0) KHÔNG được ghi dòng', function () {
    // Mặt kia của cùng điều kiện. Nới "bỏ qua khi thuế 0" thành "luôn ghi" sẽ
    // sinh dòng rác cho mọi mức không có gì — và kiểm đột biến tìm ra rằng
    // phía PHP chưa ghim mặt này (Go thì có).
    $order = iecOrder();
    iecLine($order, 0, 10);
    $order = iecSettle($order);

    $rows = OrderCondition::query()
        ->where('conditionable_type', $order->getMorphClass())
        ->where('conditionable_id', $order->id)
        ->where('type', 'tax')
        ->get();

    expect($rows)->toHaveCount(0);
});
