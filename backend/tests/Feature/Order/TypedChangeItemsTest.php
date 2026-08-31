<?php

declare(strict_types=1);

use App\Exceptions\MenuPromotionException;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\ChangeOrderItemsCommand;
use App\Services\Order\Contracts\OrderMutationFacade;
use App\Services\Order\Enums\OrderItemMutation;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * T2.12 — changeItems through the typed facade (issue #1090).
 *
 * The design under test: resolveLine validates and prices ONE line with the
 * SAME engine components legacy addItems uses (menu scope, floating price,
 * promotion + stacking guard, ToppingSelectionPricer, TaxResolver), seals the
 * result, and applyItemChange bridges to legacy addItems/updateItem so BR-OI06
 * merge semantics and the pricing recompute keep their single writer.
 *
 * What a shop must be able to rely on:
 *  - a line added mid-meal lands at the MENU's price, never the client's
 *  - the same dish added twice MERGES into one line (no duplicate kitchen slips)
 *  - an exclusive promotion cannot sneak past an applied coupon
 *  - a stale menu line from an outdated device is refused, not guessed at
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
        'service_charge_rate' => 0,
        'service_charge_tax_rate' => 0,
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

function ciMenuLine(float $price, float $rate = 10): MenuProductSku
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

function ciOrder(): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'status' => CustomerOrderStatusEnum::Open->value,
        'is_tax_included' => false,
        'discount_amount' => 0,
        'coupon_id' => null,
        'tax_rounding_mode' => 'round',
        'tax_rounding_decimals' => 0,
        'order_type' => 'spot',
    ]);
}

function ciAdd(CustomerOrder $order, MenuProductSku $line, int $qty, ?string $lineId = null): void
{
    $payload = new OrderLineSelectionPayload($lineId ?? (string) Str::uuid(), (string) $line->id, $qty);

    app(OrderMutationFacade::class)->changeItems(new ChangeOrderItemsCommand(
        new MutationContext(test()->orgId, null, (string) Str::uuid(), (string) Str::uuid(), 1),
        (string) $order->id,
        OrderItemMutation::Add,
        $payload->fingerprint(),
        $payload,
    ));
}

it('adds a line at the MENU price and reprices the order', function () {
    $order = ciOrder();
    $line = ciMenuLine(1000);

    ciAdd($order, $line, 2);

    $order->refresh()->load('items');
    expect($order->items)->toHaveCount(1)
        ->and((float) $order->items[0]->unit_price)->toBe(1000.0)
        ->and((float) $order->subtotal)->toBe(2000.0)
        ->and((float) $order->tax_amount)->toBe(200.0)
        ->and((float) $order->total_amount)->toBe(2200.0);
});

it('merges the same dish added twice into ONE line — no duplicate kitchen slips', function () {
    $order = ciOrder();
    $line = ciMenuLine(1000);

    ciAdd($order, $line, 1);
    ciAdd($order, $line, 2);

    $order->refresh()->load('items');
    // BR-OI06 through the bridge: one row, quantity 3 — the kitchen sees one
    // slip for three servings, and the bill is identical either way.
    expect($order->items)->toHaveCount(1)
        ->and((float) $order->items[0]->quantity)->toBe(3.0)
        ->and((float) $order->subtotal)->toBe(3000.0);
});

it('revises quantity through the facade and reprices', function () {
    $order = ciOrder();
    $line = ciMenuLine(1000);
    ciAdd($order, $line, 1);
    $order->refresh()->load('items');
    $itemId = (string) $order->items[0]->id;

    $payload = new OrderLineSelectionPayload($itemId, (string) $line->id, 3);
    app(OrderMutationFacade::class)->changeItems(new ChangeOrderItemsCommand(
        new MutationContext($this->orgId, null, (string) Str::uuid(), (string) Str::uuid(), 1),
        (string) $order->id,
        OrderItemMutation::Revise,
        $payload->fingerprint(),
        $payload,
        $itemId,
    ));

    $order->refresh()->load('items');
    expect((float) $order->items[0]->quantity)->toBe(3.0)
        ->and((float) $order->subtotal)->toBe(3000.0)
        ->and((float) $order->total_amount)->toBe(3300.0);
});

it('refuses an exclusive promotion when the order already carries a coupon', function () {
    $order = ciOrder();
    $coupon = Coupon::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'HELD10',
        'discount_type' => 'percent',
        'discount_value' => 10.0,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addYear(),
    ]);
    $order->forceFill(['coupon_id' => $coupon->id])->save();

    $line = ciMenuLine(1000);
    MenuPromotion::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'discount_percent' => 15,
        'applies_to' => 'all_items',
        'stacking_mode' => 'exclusive_with_coupons',
        'is_active' => true,
        'weekdays' => [],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    // Decision B5: the guard fires at RESOLUTION, before anything persists —
    // otherwise the customer sees a discount the coupon forbids.
    expect(fn () => ciAdd($order, $line, 1))
        ->toThrow(MenuPromotionException::class);

    expect($order->refresh()->items()->count())->toBe(0);
});

it('refuses a stale menu line from another branch', function () {
    $order = ciOrder();
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id,
        'status' => 'Active',
    ]);
    $foreign = ciMenuLine(1000);

    expect(fn () => ciAdd($order, $foreign, 1))
        ->toThrow(InvalidArgumentException::class, 'stale menu');
});

it('REGRESSION: the LEGACY addItems guard also refuses the exclusive-promo-on-coupon stack', function () {
    // The Decision-B5 guard in WritesCustomerOrders::addItems compared the
    // enum-cast stacking_mode against a raw string — ALWAYS false — so a
    // couponed order could stack an exclusive promotion and double-discount.
    // No test covered it; the typed-path port surfaced it. This pins the
    // revived guard on the legacy path itself.
    $order = ciOrder();
    $coupon = Coupon::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'HELD20',
        'discount_type' => 'percent',
        'discount_value' => 10.0,
        'min_order_subtotal' => 0,
        'status' => 'draft',
        'times_used' => 0,
        'usage_limit_total' => 100,
        'usage_limit_per_customer' => 0,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addYear(),
    ]);
    $order->forceFill(['coupon_id' => $coupon->id])->save();

    $line = ciMenuLine(1000);
    MenuPromotion::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'discount_percent' => 15,
        'applies_to' => 'all_items',
        'stacking_mode' => 'exclusive_with_coupons',
        'is_active' => true,
        'weekdays' => [],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    expect(fn () => app(CustomerOrderService::class)->addItems($order, ['items' => [[
        'product_sku_id' => (string) $line->product_sku_id,
        'menu_product_sku_id' => (string) $line->id,
        'quantity' => 1,
    ]]]))->toThrow(MenuPromotionException::class);

    expect($order->refresh()->items()->count())->toBe(0);
});
