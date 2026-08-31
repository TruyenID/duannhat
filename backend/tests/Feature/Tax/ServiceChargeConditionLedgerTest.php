<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderCondition;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Collection;

/**
 * #2041 — phí phục vụ phải có DÒNG trong `order_conditions`, không chỉ là cột.
 *
 * ## Vì sao đây là lỗi chứ không phải thiếu sót thẩm mỹ
 *
 * Trước bài này khoản phí bị tách làm đôi: phần THUẾ của nó đã được gấp vào dòng
 * `tax` của nhóm mức tương ứng (`OrderPricingCalculator`), nên nhìn sổ thì nền
 * chịu thuế của nhóm ấy ĐÃ gồm cả phí — nhưng bản thân khoản phí thì không có
 * dòng nào. Ai đọc sổ sẽ thấy một nền 1.050 mà tổng dòng món chỉ có 1.000, và
 * không có gì trong sổ giải thích 50 kia từ đâu ra.
 *
 * Đúng hình dạng đã sinh ra #2031: một con số vô hướng làm mất cấu trúc, rồi
 * cấu trúc phải được suy lại ở đường đọc.
 *
 * ## Bất biến thật sự được ghim
 *
 * `total_amount == subtotal + Σ(conditions.amount)` — một quy tắc thay cho bốn
 * phép đối chiếu rời. Nó chỉ đúng khi MỌI khoản tiền ngoài giá sản phẩm đều có
 * dòng, nên nó cũng chính là thứ phát hiện ra khoản phí bị thiếu.
 *
 * ## Và vì sao `tip` KHÔNG được xuất hiện ở đây
 *
 * Tip đã có bảng riêng (`order_payments.tip_amount`) và BR-P03 cố ý để nó NGOÀI
 * `total_amount` — tiền cho nhân viên, không phải doanh thu quán. Thêm tip vào
 * sổ này sẽ phá vỡ chính bất biến ở trên. Test cuối ghim quyết định đó, vì nó là
 * loại quyết định trông như một thiếu sót với người đọc sau.
 */
function serviceChargeOrder(float $chargeRate, float $chargeTaxRate): CustomerOrder
{
    $order = CustomerOrder::factory()->create([
        'status' => 'open',
        'is_tax_included' => false,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
    ]);

    ShopOrderSetting::query()->firstOrCreate(
        ['branch_id' => $order->branch_id],
        [
            'organization_id' => $order->organization_id,
            'service_charge_rate' => $chargeRate,
            'service_charge_tax_rate' => $chargeTaxRate,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => 'round',
        ]
    );

    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 1000,
        'topping_subtotal' => 0,
        'subtotal' => 1000,
        'tax_rate' => 10,
        'tax_amount' => 0,
        'status' => 'served',
    ]);

    $fresh = $order->fresh('items');
    app(CustomerOrderService::class)->refreshOrderTotals($fresh);

    return $fresh->fresh('items');
}

/** @return Collection<int, OrderCondition> */
function conditionsOf(CustomerOrder $order, string $type)
{
    return OrderCondition::query()
        ->where('conditionable_type', $order->getMorphClass())
        ->where('conditionable_id', $order->id)
        ->where('type', $type)
        ->get();
}

it('ghi một dòng service_charge khớp đúng cột', function () {
    $order = serviceChargeOrder(chargeRate: 5, chargeTaxRate: 10);

    expect((float) $order->service_charge)->toBeGreaterThan(0.0);

    $rows = conditionsOf($order, 'service_charge');

    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]->amount)->toBe((float) $order->service_charge);
    expect($rows[0]->source)->toBe('service_charge');
});

it('rate của dòng phí là MỨC THUẾ nó chịu, không phải tỉ lệ tính phí', function () {
    // Hai con số cố ý khác nhau: tính phí 5%, phí chịu thuế 10%. Nếu ai đó lưu
    // nhầm tỉ lệ tính phí vào `rate` thì test này bắt được — mà nhầm kiểu đó
    // không làm sai đồng nào lúc tính, chỉ làm sai lúc DỰNG LẠI nền chịu thuế.
    $order = serviceChargeOrder(chargeRate: 5, chargeTaxRate: 10);

    expect((float) conditionsOf($order, 'service_charge')[0]->rate)->toBe(10.0);
});

it('phí không chịu thuế thì rate NULL, không phải 0', function () {
    // Null = ngoài phạm vi thuế. `rate = 0` là một tuyên bố khác hẳn: chịu thuế
    // ở mức 0% (免税/zero-rated), thứ vẫn đi vào nền chịu thuế của tờ khai.
    $order = serviceChargeOrder(chargeRate: 5, chargeTaxRate: 0);

    $rows = conditionsOf($order, 'service_charge');

    // Khẳng định có dòng TRƯỚC đã: bọc phần kiểm tra trong `if (isNotEmpty())`
    // thì một lượt chạy không sinh dòng nào cũng xanh, và test thôi đo cái nó
    // tên là đo.
    expect($rows)->toHaveCount(1);
    expect((float) $rows[0]->amount)->toBe((float) $order->service_charge);
    expect($rows[0]->rate)->toBeNull();
});

it('không có phí thì KHÔNG ghi dòng rỗng', function () {
    $order = serviceChargeOrder(chargeRate: 0, chargeTaxRate: 10);

    expect((float) $order->service_charge)->toBe(0.0);
    expect(conditionsOf($order, 'service_charge'))->toHaveCount(0);
});

it('tính lại nhiều lần vẫn đúng MỘT dòng, không nhân đôi', function () {
    // Sổ này là loại tái sinh (xoá rồi ghi lại), không phải append. Quên thêm
    // `service_charge` vào danh sách xoá thì mỗi lần chạm đơn lại sinh thêm một
    // dòng — và tổng sổ phình lên trong khi cột đứng yên.
    $order = serviceChargeOrder(chargeRate: 5, chargeTaxRate: 10);

    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));
    app(CustomerOrderService::class)->refreshOrderTotals($order->fresh('items'));

    expect(conditionsOf($order->fresh(), 'service_charge'))->toHaveCount(1);
});

it('BẤT BIẾN: total_amount == subtotal + Σ(mọi dòng sổ)', function () {
    $order = serviceChargeOrder(chargeRate: 5, chargeTaxRate: 10);

    $sum = (float) OrderCondition::query()
        ->where('conditionable_type', $order->getMorphClass())
        ->where('conditionable_id', $order->id)
        ->sum('amount');

    expect((float) $order->subtotal + $sum)
        ->toBe((float) $order->total_amount, 'sổ không dựng lại được tổng đơn');
});

it('tip KHÔNG có dòng trong sổ này — nó thuộc order_payments', function () {
    // Ghim một QUYẾT ĐỊNH, không phải một hành vi. Tip nằm ngoài `total_amount`
    // (BR-P03) nên đưa nó vào đây sẽ phá vỡ bất biến ở test trên; và nó gắn vào
    // từng lần thanh toán, nên dời lên đơn là mất thông tin ai tip trong chia bill.
    $order = serviceChargeOrder(chargeRate: 5, chargeTaxRate: 10);

    expect(conditionsOf($order, 'tip'))->toHaveCount(0);
});
