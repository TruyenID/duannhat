<?php

declare(strict_types=1);

use App\Http\Resources\CustomerOrderResource;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderTaxBreakdownAggregator;

/**
 * #2074 — `tax_breakdown` được tính ở HAI nơi độc lập; bài này buộc chúng khớp.
 *
 * ## Hai đường
 *
 * | Nơi | Cách tính | Ai đọc |
 * |---|---|---|
 * | `OrderTaxBreakdownAggregator` | ĐỌC SỔ `order_conditions` (#2031) | báo cáo, hoá đơn |
 * | `CustomerOrderResource` | TỰ GOM từ `items`, tự phân bổ lại giảm giá | payload API |
 *
 * Đường thứ hai là đường client thật sự nhìn thấy — nó được bọc bởi
 * `/api/v1/{workstation,handy,pos}/orders` và HQ orders index.
 *
 * ## Vì sao là rào, không phải bản sửa
 *
 * Hôm nay hai bên **có thể** đang khớp. Vấn đề là **không có gì bắt chúng phải
 * khớp**: sửa một bên là đủ để chúng rẽ nhánh, và triệu chứng — "báo cáo nói
 * một đằng, phiếu in nói một nẻo" — chỉ lộ ra ở đối soát cuối kỳ.
 *
 * Đã rẽ thật một lần: #2069 sửa aggregator bỏ sót nhóm 0%, trong khi đường
 * resource `groupBy(tax_rate)` vẫn giữ nhóm ấy. Tức trước #2069 hai bên ĐÃ lệch
 * ở đúng ca đó và không test nào thấy.
 *
 * ## Vì sao dựng bằng ĐƯỜNG GHI THẬT
 *
 * `refreshOrderTotals()` là funnel duy nhất đóng dấu thuế lên từng dòng VÀ ghi
 * `order_conditions`. Nếu bài này tự seed sổ bằng tay thì hai bên khớp **theo
 * cấu tạo** — nó sẽ xanh vĩnh viễn mà không đo gì. Ở đây engine sinh cả hai đầu
 * vào, nên phép so là phép so thật.
 *
 * @see docs/guide/tax-types.md
 */
function twoReadersOrder(array $attrs = []): CustomerOrder
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

function twoReadersLine(CustomerOrder $order, float $unitPrice, ?float $rate, int $qty = 1, string $status = 'served'): CustomerOrderItem
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

function twoReadersSettle(CustomerOrder $order): CustomerOrder
{
    $fresh = $order->fresh('items');
    app(CustomerOrderService::class)->refreshOrderTotals($fresh);

    return $fresh->fresh('items');
}

/**
 * Bảng thuế mà CLIENT nhìn thấy.
 *
 * @return list<array{rate: float, taxable: float, tax: float}>
 */
function twoReadersResourceRows(CustomerOrder $order): array
{
    $payload = (new CustomerOrderResource($order->loadMissing('items')))->toArray(request());

    return $payload['tax_breakdown'] ?? [];
}

/**
 * Bảng thuế mà BÁO CÁO / HOÁ ĐƠN nhìn thấy.
 *
 * @return list<array{rate: float, taxable: float, tax: float}>
 */
function twoReadersLedgerRows(CustomerOrder $order): array
{
    return app(OrderTaxBreakdownAggregator::class)->forOrders([$order->id])['by_rate'];
}

/**
 * So từng phần tử, không so tổng.
 *
 * So tổng là phép đo yếu ở đúng chỗ này: hai bảng có thể cùng tổng mà chia sai
 * giữa các mức — mà 適格請求書 đòi con số THEO TỪNG MỨC, nên chia sai đã là
 * chứng từ sai rồi.
 */
function expectBothReadersAgree(CustomerOrder $order, string $shape): void
{
    $api = twoReadersResourceRows($order);
    $book = twoReadersLedgerRows($order);

    $fmt = fn (array $rows) => collect($rows)
        ->map(fn ($r) => sprintf('%.2f%% → nền %.2f / thuế %.2f', $r['rate'], $r['taxable'], $r['tax']))
        ->implode("\n    ");

    $msg = implode("\n", [
        "Hai đường đọc tax_breakdown LỆCH NHAU — hình dạng: {$shape}",
        '',
        '  payload API (CustomerOrderResource):',
        '    '.($api === [] ? '(rỗng)' : $fmt($api)),
        '',
        '  sổ (OrderTaxBreakdownAggregator):',
        '    '.($book === [] ? '(rỗng)' : $fmt($book)),
        '',
        'Cùng một đơn, hai con số. Một cái sẽ lên hoá đơn, cái kia lên báo cáo.',
    ]);

    expect(count($api))->toBe(count($book), $msg);

    foreach ($api as $i => $row) {
        expect($row['rate'])->toBe($book[$i]['rate'], $msg);
        // Sai số 0,01 chỉ để nuốt bụi dấu phẩy động, KHÔNG phải dung sai nghiệp
        // vụ: bước tiền tệ nhỏ nhất mà hệ này chạy là 0,001 (KWD), nên một chênh
        // lệch THẬT luôn lớn hơn ngưỡng này.
        expect(abs($row['taxable'] - $book[$i]['taxable']))->toBeLessThan(0.01, $msg);
        expect(abs($row['tax'] - $book[$i]['tax']))->toBeLessThan(0.01, $msg);
    }
}

it('MỘT mức, không giảm giá', function () {
    $order = twoReadersOrder();
    twoReadersLine($order, 1000.0, 10.0);

    expectBothReadersAgree(twoReadersSettle($order), 'một mức 10%, không giảm giá');
});

it('HAI mức, không giảm giá', function () {
    $order = twoReadersOrder();
    twoReadersLine($order, 1000.0, 10.0);
    twoReadersLine($order, 500.0, 8.0);

    expectBothReadersAgree(twoReadersSettle($order), '10% + 8%, không giảm giá');
});

it('HAI mức CÓ giảm giá — nơi #2031 đã cắn một lần', function () {
    // Đây là hình dạng sinh ra #2031: `items.subtotal` là GỘP còn
    // `items.tax_amount` đã trừ giảm giá, nên hai cột lấy từ hai mốc khác nhau.
    $order = twoReadersOrder(['discount_amount' => 300.0]);
    twoReadersLine($order, 1000.0, 10.0);
    twoReadersLine($order, 500.0, 8.0);

    expectBothReadersAgree(twoReadersSettle($order), '10% + 8%, giảm 300');
});

it('BA mức kèm nhóm 0% và có giảm giá', function () {
    // Nhóm 0% là ca đã rẽ thật: #2069 sửa aggregator bỏ sót nó, resource thì
    // `groupBy(tax_rate)` nên vẫn giữ. Hai bên từng cho hai bảng khác nhau.
    $order = twoReadersOrder(['discount_amount' => 400.0]);
    twoReadersLine($order, 1000.0, 10.0);
    twoReadersLine($order, 500.0, 8.0);
    twoReadersLine($order, 700.0, 0.0);

    expectBothReadersAgree(twoReadersSettle($order), '10% + 8% + 0%, giảm 400');
});

it('総額表示 — giá ĐÃ gồm thuế, có giảm giá', function () {
    // Chế độ 内税 dùng công thức trích ngược, và `taxable` phải là nền ĐÃ TRỪ
    // phần thuế trích ra. Hai đường tính điều đó bằng hai cách khác nhau: sổ
    // chụp `taxable_base`, resource làm `net - tax`.
    $order = twoReadersOrder(['is_tax_included' => true, 'discount_amount' => 300.0]);
    twoReadersLine($order, 1100.0, 10.0);
    twoReadersLine($order, 540.0, 8.0);

    expectBothReadersAgree(twoReadersSettle($order), '内税 10% + 8%, giảm 300');
});

it('dòng VOIDED không được vào bảng của bên nào', function () {
    $order = twoReadersOrder();
    twoReadersLine($order, 1000.0, 10.0);
    twoReadersLine($order, 9999.0, 8.0, 1, 'voided');

    $settled = twoReadersSettle($order);

    expectBothReadersAgree($settled, 'có một dòng voided');

    // Và khẳng định trực tiếp: mức 8% KHÔNG được xuất hiện. Nếu chỉ so hai bên
    // với nhau thì cả hai cùng sai vẫn xanh.
    expect(collect(twoReadersResourceRows($settled))->pluck('rate')->all())->not->toContain(8.0);
});

it('giảm giá LỚN HƠN giỏ hàng — nền phải kẹp ở 0, không âm', function () {
    // Ca suy biến: `discount_amount` vượt subtotal. Resource kẹp bằng
    // `min($discount, $subtotal)` rồi `max(0.0, …)`; sổ thì mang bất cứ thứ gì
    // engine đã ghi. Đây đúng là loại chỗ hai bản cài đặt độc lập rẽ nhau.
    $order = twoReadersOrder(['discount_amount' => 5000.0]);
    twoReadersLine($order, 1000.0, 10.0);

    $settled = twoReadersSettle($order);

    expectBothReadersAgree($settled, 'giảm giá vượt giỏ');

    foreach (twoReadersResourceRows($settled) as $row) {
        expect($row['taxable'])->toBeGreaterThanOrEqual(0.0, 'nền chịu thuế âm')
            ->and($row['tax'])->toBeGreaterThanOrEqual(0.0, 'thuế âm');
    }
});
