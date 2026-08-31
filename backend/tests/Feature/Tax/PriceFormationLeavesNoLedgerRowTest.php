<?php

declare(strict_types=1);

use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderCondition;
use App\Models\OrderItemTopping;
use App\Models\ShopOrderSetting;
use App\Services\Customer\CustomerOrderService;

/**
 * Ruling #2132 §B, chiều NGƯỢC — ba cơ chế định hình giá không để lại dòng sổ.
 *
 * Khuyến mãi thực đơn, override giá menu/floating, và topping miễn phí N cái
 * đầu đều KẾT THÚC ở `unit_price`/`topping_subtotal` của dòng, tức tiền của
 * chúng đã nằm TRONG `subtotal`. Ghi thêm một dòng `order_conditions` cho
 * chúng là đại diện kép cùng một khoản tiền trên cùng một bảng, và bất biến
 * `total_amount == subtotal + Σ(sổ)` sẽ ngừng tự kiểm tra được bằng phép cộng.
 * Ruling: `docs/explanation/money-ledger-architecture.md` §"Ruling #2132 §B".
 *
 * ## Phép đo: hai đơn TIỀN GIỐNG HỆT, chỉ khác dấu vết định hình giá
 *
 * Đơn A trả 1.000/đơn vị vì đó là giá niêm yết. Đơn B trả đúng 1.000 vì khuyến
 * mãi hạ từ 1.400, và trả đúng 200 tiền topping vì 2 trong 4 đơn vị được miễn.
 * Mọi cột tiền của hai đơn bằng nhau; khác biệt duy nhất sống ở tầng
 * item-snapshot. Nếu một cơ chế nào lọt vào sổ, census hai bên lệch nhau.
 *
 * Đếm cả HAI morph: né phép cộng cấp đơn bằng cách gắn dòng vào
 * `conditionable = CustomerOrderItem` vẫn là đại diện kép, chỉ khó thấy hơn.
 */
function priceFormationOrder(): CustomerOrder
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
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
            'tax_rounding_mode' => 'round',
        ]
    );

    return $order;
}

/**
 * @return array{0: CustomerOrderItem, 1: CustomerOrderItem}
 */
function priceFormationLines(CustomerOrder $order, ?float $originalUnitPrice, int $toppingUnits): array
{
    // Dòng 1 — giá cuối 1.000 × 2. `original_unit_price` là dấu vết định hình
    // giá, NOT NULL từ #2617: bằng chính unit_price khi không cơ chế nào hạ
    // giá dòng này (mã hoá cũ "null = không giảm" đã đổi thành original == unit).
    $promoted = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 2,
        'unit_price' => 1000.0,
        'original_unit_price' => $originalUnitPrice ?? 1000.0,
        'topping_subtotal' => 0,
        'subtotal' => 2000.0,
        'tax_rate' => 10.0,
        'tax_amount' => 0,
        'status' => 'served',
    ]);

    // Dòng 2 — 800 tiền món + 200 tiền topping. Số đơn vị topping khác nhau
    // giữa hai đơn (4 gọi / 2 tính tiền vs 2 gọi / 2 tính tiền) nhưng TIỀN thì
    // bằng nhau, vì phần miễn không sinh tiền.
    $withToppings = CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'quantity' => 1,
        'unit_price' => 800.0,
        'original_unit_price' => 800.0,
        'topping_subtotal' => 200.0,
        'subtotal' => 1000.0,
        'tax_rate' => 10.0,
        'tax_amount' => 0,
        'status' => 'served',
    ]);

    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $withToppings->id,
        'quantity' => $toppingUnits,
        'unit_price' => 100.0,
        'status' => 'served',
    ]);

    return [$promoted, $withToppings];
}

/** @return array<string, int> census "type|source|rate|amount" → số dòng, CẢ HAI morph */
function ledgerCensus(CustomerOrder $order): array
{
    $itemIds = CustomerOrderItem::where('customer_order_id', $order->id)->pluck('id');

    $rows = OrderCondition::query()
        ->where(function ($q) use ($order, $itemIds) {
            $q->where(fn ($w) => $w->where('conditionable_type', $order->getMorphClass())
                ->where('conditionable_id', $order->id))
                ->orWhere(fn ($w) => $w->where('conditionable_type', (new CustomerOrderItem)->getMorphClass())
                    ->whereIn('conditionable_id', $itemIds));
        })
        ->get();

    $census = [];
    foreach ($rows as $row) {
        $key = implode('|', [
            $row->conditionable_type === $order->getMorphClass() ? 'order' : 'item',
            (string) $row->type,
            (string) $row->source,
            (string) $row->rate,
            number_format((float) $row->amount, 2, '.', ''),
        ]);
        $census[$key] = ($census[$key] ?? 0) + 1;
    }

    ksort($census);

    return $census;
}

function priceFormationSettle(CustomerOrder $order): CustomerOrder
{
    $fresh = $order->fresh('items');
    app(CustomerOrderService::class)->refreshOrderTotals($fresh);

    return $fresh->fresh('items');
}

it('đơn có khuyến mãi + topping miễn phí sinh ĐÚNG những dòng sổ như đơn thường', function () {
    $plain = priceFormationOrder();
    priceFormationLines($plain, originalUnitPrice: null, toppingUnits: 2);
    $plain = priceFormationSettle($plain);

    $formed = priceFormationOrder();
    priceFormationLines($formed, originalUnitPrice: 1400.0, toppingUnits: 4);
    $formed = priceFormationSettle($formed);

    // Tiền hai đơn phải bằng nhau — nếu không, phép so census bên dưới vô nghĩa.
    expect((float) $formed->subtotal)->toBe((float) $plain->subtotal)
        ->and((float) $formed->total_amount)->toBe((float) $plain->total_amount);

    expect(ledgerCensus($formed))->toBe(ledgerCensus($plain));
});

it('không dòng sổ nào mang source của một cơ chế định hình giá', function () {
    $formed = priceFormationOrder();
    priceFormationLines($formed, originalUnitPrice: 1400.0, toppingUnits: 4);
    $formed = priceFormationSettle($formed);

    $sources = array_map(
        static fn (string $key): string => explode('|', $key)[2],
        array_keys(ledgerCensus($formed)),
    );

    foreach (['menu_promotion', 'price_override', 'free_topping', 'promotion', 'waived'] as $banned) {
        expect(in_array($banned, $sources, true))->toBeFalse(
            "sổ mọc dòng `source = {$banned}` — ruling #2132 §B: định hình giá sống ở item-snapshot, không vào sổ"
        );
    }
});

it('sổ KHÔNG có dòng nào gắn vào item — census còn đo được cả morph item', function () {
    // Chống xanh giả cho hai test trên: nếu hôm nay đã có dòng item-morph thì
    // phép so census vẫn xanh (hai bên cùng có), nên khẳng định riêng ở đây.
    $formed = priceFormationOrder();
    priceFormationLines($formed, originalUnitPrice: 1400.0, toppingUnits: 4);
    $formed = priceFormationSettle($formed);

    $census = ledgerCensus($formed);

    expect($census)->not->toBe([], 'không có dòng sổ nào — harness đã hỏng, phép so census không đo gì');

    $morphs = array_map(static fn (string $key): string => explode('|', $key)[0], array_keys($census));
    expect(in_array('item', $morphs, true))->toBeFalse();

    // Và census thật sự phân biệt được morph item nếu có một dòng như thế.
    $item = CustomerOrderItem::where('customer_order_id', $formed->id)->firstOrFail();
    OrderCondition::query()->create([
        'conditionable_type' => $item->getMorphClass(),
        'conditionable_id' => $item->id,
        'type' => 'discount',
        'source' => 'menu_promotion',
        'label' => 'chứng nhân',
        'amount' => -400,
        'currency_code' => 'JPY',
    ]);

    $withWitness = array_map(static fn (string $key): string => explode('|', $key)[0], array_keys(ledgerCensus($formed)));
    expect(in_array('item', $withWitness, true))->toBeTrue();
});
