<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\CustomerOrderTypeEnum;
use App\Omnify\Enums\OrderItemPriceSourceEnum;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Results\OrderCreatedResult;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderSelectionPayload;
use App\Services\Order\ValueObjects\OrderToppingSelectionPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * T2.12 — the FULL typed create path, end to end (issue #1090).
 *
 * OrderService::create → resolveOrder (typed evidence) → insertResolvedOrder
 * (persists through the legacy insertOrder funnel, then re-derives the totals
 * with the legacy engine and REFUSES to commit if the two disagree).
 *
 * That last step is the safety property this file cares about most: a resolver
 * drift must never become a customer's bill. It becomes a rollback with the
 * exact minor-unit gap in the message.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0));

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
    ]);
    ShopOrderSetting::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'service_charge_rate' => 10,
        'service_charge_tax_rate' => 10,
        'currency_code' => 'JPY',
        'prices_include_tax' => false,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
        'default_order_item_status' => 'pending',
        'enable_quick_order' => false,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function e2eMenuLine(float $price, float $rate): MenuProductSku
{
    $product = Product::factory()->active()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'product_type_id' => test()->productType->id,
        'tax_type_id' => TaxType::factory()->create([
            'organization_id' => test()->orgId,
            'brand_id' => test()->brand->id,
            'rate' => $rate,
            'is_active' => true,
            'is_default' => false,
        ])->id,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => $price,
        'is_active' => true,
    ]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => test()->menu->id,
        'product_id' => $product->id,
        'is_active' => true,
        'tax_type_id' => null,
    ]);

    return MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => $price,
    ]);
}

/** @param list<array{0: MenuProductSku, 1: int}> $lines */
function e2eCreate(array $lines, ?string $orderId = null, ?string $idempotencyKey = null): OrderCreatedResult
{
    $selection = new OrderSelectionPayload(array_map(
        fn (array $pair) => new OrderLineSelectionPayload((string) Str::uuid(), (string) $pair[0]->id, $pair[1]),
        $lines,
    ));

    return app(OrderMutationFacade::class)->create(new CreateOrderCommand(
        new MutationContext(test()->orgId, null, (string) Str::uuid(), $idempotencyKey ?? (string) Str::uuid(), 1),
        $orderId ?? (string) Str::uuid(),
        (string) test()->branch->id,
        $selection,
        $selection->fingerprint(),
    ));
}

it('creates a fully billed order through the typed facade: ¥1,000 @10% + taxed 10% service = ¥1,210', function () {
    $result = e2eCreate([[e2eMenuLine(1000, 10), 1]]);

    $order = CustomerOrder::with('items')->findOrFail($result->orderId);

    // The customer's bill, the shop's ledger, and the receipt arithmetic all
    // land on the same ¥1,210 — the number this whole plan exists to protect.
    expect((float) $order->subtotal)->toBe(1000.0)
        ->and((float) $order->service_charge)->toBe(100.0)
        ->and((float) $order->tax_amount)->toBe(110.0)
        ->and((float) $order->total_amount)->toBe(1210.0)
        ->and($order->items)->toHaveCount(1)
        ->and((float) $order->items[0]->tax_amount)->toBe(100.0)
        ->and($result->itemCount)->toBe(1);
});

it('mints an order code and stamps the tax snapshots through the legacy funnel', function () {
    $result = e2eCreate([[e2eMenuLine(1000, 10), 1]]);
    $order = CustomerOrder::findOrFail($result->orderId);

    // These come from insertOrder — proof the typed path reuses the single
    // legacy creation funnel instead of forking code minting or snapshots.
    expect($order->order_code)->not->toBeNull()
        ->and($order->qr_token)->not->toBeNull()
        ->and((bool) $order->is_tax_included)->toBeFalse()
        ->and($order->tax_rounding_mode)->toBe('round')
        ->and($order->status)->toBe(CustomerOrderStatusEnum::Open)
        ->and($order->order_type)->toBe(CustomerOrderTypeEnum::Spot);
});

it('persists the promotion strikethrough and per-line tax on the item rows', function () {
    $lineA = e2eMenuLine(1234, 8);
    $lineB = e2eMenuLine(1234, 8);

    $result = e2eCreate([[$lineA, 1], [$lineB, 1]]);
    $order = CustomerOrder::with('items')->findOrFail($result->orderId);

    // Group-once rounding: the two 8% lines share ¥197 of tax between them —
    // never 99+99=198. The split may be uneven; the SUM is the law.
    expect($order->items->sum(fn ($i) => (float) $i->tax_amount))->toBe(197.0);

    // The fixture branch levies a 10% service charge taxed at 10%:
    // subtotal 2468 → service ¥247, tax on it ¥25. The order's tax is
    // line tax + service-charge tax; the ¥25 gap between the two figures
    // is exactly the amount the old model had nowhere to put (#1090).
    expect((float) $order->service_charge)->toBe(247.0)
        ->and((float) $order->tax_amount)->toBe(222.0)
        ->and((float) $order->tax_amount - $order->items->sum(fn ($i) => (float) $i->tax_amount))->toBe(25.0);
});

it('stamps original_unit_price on every typed-path line, equal to unit_price when nothing discounted', function () {
    // #2617 (ruling #2132 §B) — dấu vết định hình giá là bắt buộc trên MỌI
    // dòng; đường evidence từng ghi NULL khi resolver không mang strikethrough
    // (và OfflineOrderEvidenceVerifier LUÔN gửi null ở chỗ đó).
    $result = e2eCreate([[e2eMenuLine(1000, 10), 2]]);
    $order = CustomerOrder::with('items')->findOrFail($result->orderId);
    $item = $order->items->firstOrFail();

    expect($item->original_unit_price)->not->toBeNull()
        ->and((float) $item->original_unit_price)->toBe(1000.0)
        ->and((float) $item->unit_price)->toBe(1000.0);
});

it('replays the same typed create idempotently instead of double-billing', function () {
    $line = e2eMenuLine(1000, 10);
    $orderId = (string) Str::uuid();

    $first = e2eCreate([[$line, 1]], $orderId, 'replay-key-1');
    $second = e2eCreate([[$line, 1]], $orderId, 'replay-key-1');

    // A network retry of the SAME create lands on the SAME order. Two orders
    // here means a table gets billed twice for one submission.
    expect($second->orderId)->toBe($first->orderId)
        ->and(CustomerOrder::query()->where('branch_id', $this->branch->id)->count())->toBe(1);
});

it('honours a quick-order branch: lines land served with served_at, money unchanged', function () {
    // 早出しモード — the counter hands food over immediately, so every line
    // must persist as served or the KDS shows phantom cooking work forever.
    ShopOrderSetting::query()->where('branch_id', $this->branch->id)
        ->update(['default_order_item_status' => 'served']);
    $line = e2eMenuLine(1000, 10);

    $result = e2eCreate([[$line, 1]]);
    $order = CustomerOrder::with('items')->findOrFail($result->orderId);
    $item = $order->items->firstOrFail();

    expect($item->status->value ?? (string) $item->status)->toBe('served')
        ->and($item->served_at)->not->toBeNull()
        // Status is workflow, not money: the bill is identical to a
        // pending-mode branch.
        ->and((float) $order->total_amount)->toBe(1210.0);
});

it('persists topping rows and bills the topping through the full facade', function () {
    $line = e2eMenuLine(1000, 10);
    $group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ]);
    $toppingProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => true,
        'selling_price' => 200,
    ]);
    $toppingItem = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $toppingProduct->id,
        'is_default' => false,
        'sort_order' => 0,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $toppingItem->id,
        'product_sku_id' => $toppingSku->id,
        'extra_price' => 200,
    ]);
    $line->productSku->product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

    $selection = new OrderSelectionPayload([
        new OrderLineSelectionPayload((string) Str::uuid(), (string) $line->id, 1, [
            new OrderToppingSelectionPayload((string) $toppingItem->id, (string) $toppingSku->id, 1),
        ]),
    ]);
    $result = app(OrderMutationFacade::class)->create(new CreateOrderCommand(
        new MutationContext($this->orgId, null, (string) Str::uuid(), (string) Str::uuid(), 1),
        (string) Str::uuid(),
        (string) $this->branch->id,
        $selection,
        $selection->fingerprint(),
    ));

    $order = CustomerOrder::with('items.orderItemToppings')->findOrFail($result->orderId);
    $item = $order->items->firstOrFail();

    // Bill: dish 1000 + topping 200 = 1200 taxable @10% → tax 120;
    // service 10% of 1200 = 120, its tax 12 → total 1452.
    expect((float) $order->subtotal)->toBe(1200.0)
        ->and((float) $item->topping_subtotal)->toBe(200.0)
        ->and((float) $order->total_amount)->toBe(1452.0);

    // The kitchen slip needs the actual chosen tuple, not just money.
    expect($item->orderItemToppings)->toHaveCount(1)
        ->and((string) $item->orderItemToppings[0]->topping_group_item_id)->toBe((string) $toppingItem->id)
        ->and((string) $item->orderItemToppings[0]->product_sku_id)->toBe((string) $toppingSku->id)
        ->and((float) $item->orderItemToppings[0]->unit_price)->toBe(200.0);
});

it('carries price_source and waived_quantity through the typed evidence into the persisted rows (#2618/#2619)', function () {
    $line = e2eMenuLine(1000, 10);
    $group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'free_up_to_n',
        'free_quantity' => 1,
    ]);
    $picks = [];
    foreach ([100.0, 300.0] as $price) {
        $toppingProduct = Product::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
        ]);
        $toppingSku = ProductSku::factory()->create([
            'product_id' => $toppingProduct->id,
            'is_active' => true,
            'selling_price' => $price,
        ]);
        $toppingItem = ToppingGroupItem::factory()->create([
            'topping_group_id' => $group->id,
            'product_id' => $toppingProduct->id,
            'is_default' => false,
            'sort_order' => 0,
        ]);
        ToppingGroupItemSku::factory()->create([
            'topping_group_item_id' => $toppingItem->id,
            'product_sku_id' => $toppingSku->id,
            'extra_price' => $price,
        ]);
        $picks[] = new OrderToppingSelectionPayload((string) $toppingItem->id, (string) $toppingSku->id, 1);
    }
    $line->productSku->product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

    $selection = new OrderSelectionPayload([
        new OrderLineSelectionPayload((string) Str::uuid(), (string) $line->id, 1, $picks),
    ]);
    $result = app(OrderMutationFacade::class)->create(new CreateOrderCommand(
        new MutationContext($this->orgId, null, (string) Str::uuid(), (string) Str::uuid(), 1),
        (string) Str::uuid(),
        (string) $this->branch->id,
        $selection,
        $selection->fingerprint(),
    ));

    $order = CustomerOrder::with('items.orderItemToppings')->findOrFail($result->orderId);
    $item = $order->items->firstOrFail();

    // #2618 — dòng neo menu line, không floating/promotion ⇒ nguồn = menu,
    // qua đường evidence → EloquentOrderPersistence (không phải addItems).
    expect($item->price_source)->toBe(OrderItemPriceSourceEnum::Menu)
        // #2619 — free_up_to_n miễn hàng ĐẮT (300), tính hàng rẻ (100).
        ->and((float) $item->topping_subtotal)->toBe(100.0);

    $rows = $item->orderItemToppings->sortBy('unit_price')->values();
    expect($rows->map(fn ($r) => [(float) $r->unit_price, (int) $r->waived_quantity])->all())->toBe([
        [100.0, 0],
        [300.0, 1],
    ]);
});

it('applies a create-time coupon through the same CouponService as legacy, after the witness', function () {
    $line = e2eMenuLine(1000, 10);
    Coupon::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'TYPED20',
        'discount_type' => 'percent',
        'discount_value' => 20.0,
        'max_discount_cap' => null,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0, // unlimited sentinel — no customer identity at QR create
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addYear(),
    ]);

    $selection = new OrderSelectionPayload(
        [new OrderLineSelectionPayload((string) Str::uuid(), (string) $line->id, 1)],
        couponCode: 'TYPED20',
    );
    $result = app(OrderMutationFacade::class)->create(new CreateOrderCommand(
        new MutationContext($this->orgId, null, (string) Str::uuid(), (string) Str::uuid(), 1),
        (string) Str::uuid(),
        (string) $this->branch->id,
        $selection,
        $selection->fingerprint(),
    ));

    $order = CustomerOrder::findOrFail($result->orderId);

    // 1000 − 20% = taxable 800 → tax 80; service 10% of 800 = 80, its tax 8.
    // Total 968 — CouponService recomputed everything, exactly as the legacy
    // atomic create+coupon flow does.
    expect($order->coupon_id)->not->toBeNull()
        ->and((float) $order->discount_amount)->toBe(200.0)
        ->and((float) $order->service_charge)->toBe(80.0)
        ->and((float) $order->tax_amount)->toBe(88.0)
        ->and((float) $order->total_amount)->toBe(968.0);
});

it('rolls the WHOLE order back when the create-time coupon is invalid', function () {
    $line = e2eMenuLine(1000, 10);

    $selection = new OrderSelectionPayload(
        [new OrderLineSelectionPayload((string) Str::uuid(), (string) $line->id, 1)],
        couponCode: 'NO-SUCH-CODE',
    );

    // Same rollback semantics as the legacy atomic flow: an invalid code must
    // never land an uncouponed order the customer thinks is discounted.
    expect(fn () => app(OrderMutationFacade::class)->create(new CreateOrderCommand(
        new MutationContext($this->orgId, null, (string) Str::uuid(), (string) Str::uuid(), 1),
        (string) Str::uuid(),
        (string) $this->branch->id,
        $selection,
        $selection->fingerprint(),
    )))->toThrow(Exception::class);

    expect(CustomerOrder::query()->where('branch_id', $this->branch->id)->count())->toBe(0);
});

/**
 * plan-055 T5.1 (#1826) — the online funnel must NEVER stamp `offline_replayed_at`.
 *
 * That stamp waives the payment policy check, so if the online path ever set it
 * the waiver would stop being a narrow exception for money already in the till
 * and become a universal bypass — the exact hole plan-055 exists to close.
 *
 * This assertion lives here, on the REAL create funnel, on purpose: the same
 * check written against a `CustomerOrder::factory()` order passes no matter what
 * the production code does, because the factory never runs it.
 */
it('never stamps offline_replayed_at on an order created online', function () {
    $result = e2eCreate([[e2eMenuLine(1000, 10), 1]]);

    expect(CustomerOrder::findOrFail($result->orderId)->offline_replayed_at)->toBeNull();
});
