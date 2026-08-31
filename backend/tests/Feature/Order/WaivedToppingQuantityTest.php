<?php

declare(strict_types=1);

use App\Http\Resources\CustomerOrderItemResource;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderCondition;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Customer\CustomerOrderService;
use App\Services\Topping\ToppingPricingService;
use Illuminate\Support\Str;

/**
 * #2619 (ruling #2132 §B) — `waived_quantity` trên `order_item_toppings`:
 * dấu vết CÁI NÀO được free_up_to_n miễn, không chỉ tổng đã miễn.
 *
 * Bất biến trên một dòng món:
 *   Σ(unit_price × quantity) − topping_subtotal == Σ(waived_quantity × unit_price)
 *
 * priceFreeUpToN miễn N đơn vị ĐẮT NHẤT (Toast/Square default), nên hàng đắt
 * nhận waived trước; nhóm flat (không free_quantity) giữ 0 toàn bộ.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->taxType = TaxType::factory()->standard()->asDefault()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'service_charge_rate' => 0,
        'currency_code' => 'JPY',
        'prices_include_tax' => false,
    ]);

    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'tax_type_id' => $this->taxType->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    $this->service = app(CustomerOrderService::class);
});

function wtqOrder(): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'WTQ-'.Str::random(6),
        'order_type' => 'spot',
        'status' => 'open',
        'subtotal' => 0,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

/**
 * Nhóm topping gắn vào product fixture + N item với giá cho trước.
 *
 * @param  list<float>  $prices
 * @return array{0: ToppingGroup, 1: list<array{item: ToppingGroupItem, sku: ProductSku}>}
 */
function wtqGroup(array $groupAttrs, array $prices): array
{
    $group = ToppingGroup::factory()->create(array_merge([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ], $groupAttrs));

    $items = [];
    foreach ($prices as $price) {
        $toppingProduct = Product::factory()->create([
            'organization_id' => test()->orgId,
            'brand_id' => test()->brand->id,
        ]);
        $toppingSku = ProductSku::factory()->create([
            'product_id' => $toppingProduct->id,
            'is_active' => true,
            'selling_price' => $price,
        ]);
        $item = ToppingGroupItem::factory()->create([
            'topping_group_id' => $group->id,
            'product_id' => $toppingProduct->id,
            'is_default' => false,
            'sort_order' => 0,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $item->id,
            'product_sku_id' => $toppingSku->id,
            'extra_price' => $price,
        ]);
        $items[] = ['item' => $item, 'sku' => $toppingSku];
    }

    test()->product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

    return [$group, $items];
}

/** @param list<array{item: ToppingGroupItem, sku: ProductSku}> $picks */
function wtqToppingsPayload(array $picks): array
{
    return array_map(static fn (array $p): array => [
        'topping_group_item_id' => (string) $p['item']->id,
        'product_sku_id' => (string) $p['sku']->id,
        'quantity' => 1,
    ], $picks);
}

// ---------------------------------------------------------------------------
// addItems — free_up_to_n miễn N hàng đắt nhất và ghi vết đúng HÀNG nào
// ---------------------------------------------------------------------------

it('stamps waived_quantity on the exact rows free_up_to_n waived, and the money invariant holds', function () {
    [, $items] = wtqGroup(['price_strategy' => 'free_up_to_n', 'free_quantity' => 2, 'max_select' => 5], [50.0, 80.0, 120.0, 200.0]);
    $order = wtqOrder();

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
        'toppings' => wtqToppingsPayload($items),
    ]]]);

    $line = $order->items()->firstOrFail();
    // Miễn 200 + 120 (hai hàng đắt nhất), tính tiền 50 + 80.
    expect((float) $line->topping_subtotal)->toBe(130.0);

    $rows = OrderItemTopping::where('customer_order_item_id', $line->id)
        ->orderBy('unit_price')
        ->get(['unit_price', 'quantity', 'waived_quantity']);

    expect($rows->map(fn ($r) => [(float) $r->unit_price, (int) $r->waived_quantity])->all())->toBe([
        [50.0, 0],
        [80.0, 0],
        [120.0, 1],
        [200.0, 1],
    ]);

    // Bất biến #2619: gross − net == tổng đã miễn, đọc được TỪ TỪNG HÀNG.
    $gross = $rows->sum(fn ($r) => (float) $r->unit_price * (int) $r->quantity);
    $waivedMoney = $rows->sum(fn ($r) => (float) $r->unit_price * (int) $r->waived_quantity);
    expect($gross - (float) $line->topping_subtotal)->toBe($waivedMoney)
        ->and($waivedMoney)->toBe(320.0);
});

it('keeps waived_quantity at 0 on every row of a flat group', function () {
    [, $items] = wtqGroup(['price_strategy' => 'flat', 'max_select' => 5], [50.0, 80.0]);
    $order = wtqOrder();

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
        'toppings' => wtqToppingsPayload($items),
    ]]]);

    $line = $order->items()->firstOrFail();
    expect((float) $line->topping_subtotal)->toBe(130.0);

    $rows = OrderItemTopping::where('customer_order_item_id', $line->id)->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn ($r) => (int) $r->waived_quantity === 0))->toBeTrue();
});

it('keeps waived_quantity at 0 when free_up_to_n has free_quantity = 0 (behaves as flat)', function () {
    [, $items] = wtqGroup(['price_strategy' => 'free_up_to_n', 'free_quantity' => 0, 'max_select' => 5], [50.0, 80.0]);
    $order = wtqOrder();

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
        'toppings' => wtqToppingsPayload($items),
    ]]]);

    $line = $order->items()->firstOrFail();
    $rows = OrderItemTopping::where('customer_order_item_id', $line->id)->get();
    expect((float) $line->topping_subtotal)->toBe(130.0)
        ->and($rows->every(fn ($r) => (int) $r->waived_quantity === 0))->toBeTrue();
});

// ---------------------------------------------------------------------------
// updateItem — thay topping trên dòng pending re-attribute lại vết miễn
// ---------------------------------------------------------------------------

it('re-stamps waived_quantity when a pending line replaces its toppings', function () {
    [, $items] = wtqGroup(['price_strategy' => 'free_up_to_n', 'free_quantity' => 1, 'max_select' => 5], [50.0, 120.0]);
    $order = wtqOrder();

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
        'toppings' => wtqToppingsPayload([$items[0]]),
    ]]]);
    $line = $order->items()->firstOrFail();
    // Một topping duy nhất, chính nó được miễn.
    expect((float) $line->topping_subtotal)->toBe(0.0)
        ->and((int) OrderItemTopping::where('customer_order_item_id', $line->id)->value('waived_quantity'))->toBe(1);

    // Thay bằng CẢ HAI topping: miễn hàng đắt (120), tính hàng rẻ (50).
    test()->service->updateItem($order->fresh(), (string) $line->id, [
        'toppings' => wtqToppingsPayload($items),
    ]);

    $rows = OrderItemTopping::where('customer_order_item_id', $line->id)
        ->orderBy('unit_price')
        ->get(['unit_price', 'waived_quantity']);
    expect($rows->map(fn ($r) => [(float) $r->unit_price, (int) $r->waived_quantity])->all())->toBe([
        [50.0, 0],
        [120.0, 1],
    ])
        ->and((float) $order->items()->firstOrFail()->topping_subtotal)->toBe(50.0);
});

// ---------------------------------------------------------------------------
// Mức pricer — selection nhiều đơn vị được miễn MỘT PHẦN
// ---------------------------------------------------------------------------

it('attributes a PARTIAL waiver inside a multi-unit selection at the pricer level', function () {
    $pricer = app(ToppingPricingService::class);

    // Một selection 4 đơn vị giá 100, miễn 2 ⇒ waived_by_selection = [2].
    $single = $pricer->priceLine([
        ['topping_group_item_id' => (string) Str::uuid(), 'product_sku_id' => (string) Str::uuid(), 'quantity' => 4, 'unit_price' => 100.0],
    ], 'free_up_to_n', 2);
    expect($single['topping_subtotal'])->toBe(200.0)
        ->and($single['waived_by_selection'])->toBe([2]);

    // Hỗn hợp: [100 ×2, 300 ×1], miễn 2 ⇒ miễn 300 (đắt nhất) + MỘT đơn vị 100.
    $mixed = $pricer->priceLine([
        ['topping_group_item_id' => (string) Str::uuid(), 'product_sku_id' => (string) Str::uuid(), 'quantity' => 2, 'unit_price' => 100.0],
        ['topping_group_item_id' => (string) Str::uuid(), 'product_sku_id' => (string) Str::uuid(), 'quantity' => 1, 'unit_price' => 300.0],
    ], 'free_up_to_n', 2);
    expect($mixed['topping_subtotal'])->toBe(100.0)
        ->and($mixed['waived_by_selection'])->toBe([1, 1]);

    // Flat: không hàng nào miễn.
    $flat = $pricer->priceLine([
        ['topping_group_item_id' => (string) Str::uuid(), 'product_sku_id' => (string) Str::uuid(), 'quantity' => 3, 'unit_price' => 100.0],
    ], 'flat', 0);
    expect($flat['waived_by_selection'])->toBe([0]);
});

// ---------------------------------------------------------------------------
// Rào ruling #2132 §B — vết miễn KHÔNG kéo theo dòng sổ nào
// ---------------------------------------------------------------------------

it('grows no order_conditions row for the waived toppings', function () {
    [, $items] = wtqGroup(['price_strategy' => 'free_up_to_n', 'free_quantity' => 2, 'max_select' => 5], [50.0, 80.0, 120.0, 200.0]);
    $order = wtqOrder();

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
        'toppings' => wtqToppingsPayload($items),
    ]]]);

    $sources = OrderCondition::query()
        ->where('conditionable_type', $order->getMorphClass())
        ->where('conditionable_id', $order->id)
        ->pluck('source')
        ->all();

    foreach (['free_topping', 'waived', 'menu_promotion', 'price_override'] as $banned) {
        expect(in_array($banned, $sources, true))->toBeFalse(
            "sổ mọc dòng source = {$banned} — vết miễn topping sống ở item-snapshot, không vào order_conditions"
        );
    }
});

// ---------------------------------------------------------------------------
// #2620 — dấu vết phải RA ĐƯỢC DÂY, không chỉ nằm trong DB
// ---------------------------------------------------------------------------

/**
 * `CustomerOrderItemResource` dựng mảng `toppings[]` BẰNG TAY — nó không đi qua
 * resource Omnify — nên một cột mới KHÔNG tự lên dây. Mọi test ở trên đọc thẳng
 * DB nên vẫn xanh trong khi máy trạm không bao giờ nhận được `waived_quantity`:
 * đúng hình dạng #2622 ("thêm cột vào schema không làm nó tới được qua HTTP").
 *
 * Máy trạm mirror trường này ở #2620; nếu Cloud không phát, bản mirror đó im
 * lặng ghi 0 cho mọi dòng và bất biến tiền không đọc ngược được ở LAN.
 */
it('emits waived_quantity in the toppings[] the workstation feed serializes', function () {
    [, $items] = wtqGroup(['price_strategy' => 'free_up_to_n', 'free_quantity' => 2, 'max_select' => 5], [50.0, 80.0, 120.0, 200.0]);
    $order = wtqOrder();

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $this->sku->id,
        'quantity' => 1,
        'toppings' => wtqToppingsPayload($items),
    ]]]);

    $line = $order->items()->firstOrFail()->load('orderItemToppings.toppingGroupItem.toppingGroup');

    $wire = (new CustomerOrderItemResource($line))
        ->toArray(request());

    expect($wire)->toHaveKey('toppings');

    $byPrice = collect($wire['toppings'])
        ->mapWithKeys(fn ($t) => [(string) (float) $t['unit_price'] => $t])
        ->all();

    // Mỗi hàng phải MANG khoá — thiếu khoá là im lặng, không phải 0.
    //
    // `toHaveKey($key, $value)` nhận đối số thứ hai là GIÁ TRỊ mong đợi, không
    // phải thông điệp lỗi (đúng cái bẫy "matcher nuốt thông điệp" ở policyDocs.test
    // — bản đầu của test này truyền chuỗi mô tả vào đó và đỏ vì so key với chuỗi đó).
    // Nên khẳng định danh sách khoá bằng một phép so tường minh.
    foreach ($byPrice as $price => $t) {
        expect(array_keys($t))->toContain('waived_quantity');
    }

    // Và mang đúng con số của hai hàng đắt nhất.
    expect((int) $byPrice['120']['waived_quantity'])->toBe(1)
        ->and((int) $byPrice['200']['waived_quantity'])->toBe(1)
        ->and((int) $byPrice['50']['waived_quantity'])->toBe(0)
        ->and((int) $byPrice['80']['waived_quantity'])->toBe(0);

    // Bất biến #2619 đọc được HOÀN TOÀN từ payload, không cần chạm DB.
    $gross = collect($wire['toppings'])->sum(fn ($t) => (float) $t['unit_price'] * (int) $t['quantity']);
    $waived = collect($wire['toppings'])->sum(fn ($t) => (float) $t['unit_price'] * (int) $t['waived_quantity']);
    expect($gross - (float) $line->topping_subtotal)->toBe($waived)
        ->and($waived)->toBe(320.0);
});
