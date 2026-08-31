<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
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
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Services\Customer\CustomerOrderService;
use App\Services\DomainMutation\MutationContext;
use App\Services\Order\Commands\CreateOrderCommand;
use App\Services\Order\Internal\CustomerOrderPricingResolution;
use App\Services\Order\ValueObjects\OrderLineSelectionPayload;
use App\Services\Order\ValueObjects\OrderSelectionPayload;
use App\Services\Order\ValueObjects\OrderToppingSelectionPayload;
use App\Services\Order\ValueObjects\TrustedOrderSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * T2.12 — the typed pricing resolver against BOTH oracles (issue #1090).
 *
 * Two layers of proof, because "the resolver looks right" is exactly how the
 * last five money bugs shipped:
 *
 *   1. FIXED NUMBERS — the arithmetic cases pinned by OrderPricingOracleTest,
 *      re-asserted here through the typed path (¥1,000 @10% → ¥1,100; group
 *      rounding 197 not 198; ceil/floor at the 123.4 boundary; 内税 not added
 *      on top; taxed service charge = ¥1,210).
 *
 *   2. LIVE PARITY — the same menu fixture is ALSO priced by the real legacy
 *      engine (create → addItems → refreshOrderTotals) and the two results are
 *      compared field by field. If either engine drifts, this fails with the
 *      exact yen difference, naming which side moved.
 *
 * Every case freezes the clock (#1091): promotions and menus are windowed, and
 * an unfrozen fixture here would go red on whatever weekday or midnight the CI
 * happens to hit.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 1, 8, 12, 0, 0)); // Thursday midday

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
});

afterEach(function () {
    Carbon::setTestNow();
});

function tprSetting(array $overrides = []): ShopOrderSetting
{
    return ShopOrderSetting::query()->updateOrCreate(
        ['branch_id' => test()->branch->id],
        array_merge([
            'organization_id' => test()->orgId,
            'service_charge_rate' => 0,
            'service_charge_tax_rate' => 0,
            'currency_code' => 'JPY',
            'prices_include_tax' => false,
            'tax_rounding_mode' => 'round',
            'tax_rounding_decimals' => 0,
        ], $overrides),
    );
}

function tprTaxType(float $rate): TaxType
{
    return TaxType::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'rate' => $rate,
        'is_active' => true,
        'is_default' => false,
    ]);
}

/** A sellable product on the branch menu at $menuPrice, taxed at $rate. */
function tprMenuLine(float $menuPrice, float $rate): MenuProductSku
{
    $product = Product::factory()->active()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'product_type_id' => test()->productType->id,
        'tax_type_id' => tprTaxType($rate)->id,
    ]);
    $sku = ProductSku::factory()->create([
        'product_id' => $product->id,
        'selling_price' => $menuPrice,
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
        'selling_price' => $menuPrice,
    ]);
}

/** @param list<array{0: MenuProductSku, 1: int}> $linesWithQty */
function tprResolve(array $linesWithQty): TrustedOrderSnapshot
{
    $selection = new OrderSelectionPayload(array_map(
        fn (array $pair) => new OrderLineSelectionPayload((string) Str::uuid(), (string) $pair[0]->id, $pair[1]),
        $linesWithQty,
    ));

    $command = new CreateOrderCommand(
        new MutationContext(test()->orgId, null, (string) Str::uuid(), (string) Str::uuid(), 1),
        (string) Str::uuid(),
        (string) test()->branch->id,
        $selection,
        $selection->fingerprint(),
    );

    return app(CustomerOrderPricingResolution::class)->resolveOrder($command);
}

/**
 * Price the SAME lines through the real legacy engine and return the persisted
 * order — the live half of the parity proof.
 *
 * @param  list<array{0: MenuProductSku, 1: int}>  $linesWithQty
 */
function tprLegacyOrder(array $linesWithQty): CustomerOrder
{
    $service = app(CustomerOrderService::class);
    $order = $service->create([
        'order_type' => 'spot',
        'status' => CustomerOrderStatusEnum::Open->value,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $service->addItems($order, [
        'items' => array_map(fn (array $pair) => [
            'product_sku_id' => (string) $pair[0]->product_sku_id,
            'menu_product_sku_id' => (string) $pair[0]->id,
            'quantity' => $pair[1],
        ], $linesWithQty),
    ]);
    $service->refreshOrderTotals($order->fresh('items'));

    return $order->fresh('items');
}

/** Field-by-field comparison, reporting the exact minor-unit gap per field. */
function tprAssertParity(TrustedOrderSnapshot $snapshot, CustomerOrder $legacy): void
{
    $evidence = $snapshot->draft->pricingEvidence;
    $pairs = [
        'subtotal' => [(int) round((float) $legacy->subtotal), $evidence->subtotalMinor],
        'service_charge' => [(int) round((float) $legacy->service_charge), $evidence->serviceChargeMinor],
        'tax' => [(int) round((float) $legacy->tax_amount), $evidence->taxMinor],
        'total' => [(int) round((float) $legacy->total_amount), $evidence->totalMinor],
    ];

    foreach ($pairs as $field => [$legacyMinor, $typedMinor]) {
        expect($typedMinor)->toBe(
            $legacyMinor,
            "PARITY BREAK on {$field}: legacy engine bills {$legacyMinor} but the typed resolver bills {$typedMinor} (off by ".($typedMinor - $legacyMinor).' minor units). One of the two engines drifted — find out which before anything ships.',
        );
    }

    // The per-line tax evidence must also equal what legacy stamped per row.
    $legacyLineTaxes = $legacy->items->map(fn ($i) => (int) round((float) $i->tax_amount))->sort()->values()->all();
    $typedLineTaxes = collect($snapshot->draft->lines)->map(fn ($l) => $l->evidence->taxAmountMinor)->sort()->values()->all();
    expect($typedLineTaxes)->toBe($legacyLineTaxes, 'Per-line tax allocation differs between the engines — the インボイス per-rate breakdown would not match the receipt.');
}

// ---------------------------------------------------------------------------
// Fixed-number oracle cases through the typed path
// ---------------------------------------------------------------------------

it('prices one ¥1,000 line at 10% as ¥1,100', function () {
    tprSetting();
    $snapshot = tprResolve([[tprMenuLine(1000, 10), 1]]);

    $e = $snapshot->draft->pricingEvidence;
    expect($e->subtotalMinor)->toBe(1000)
        ->and($e->taxMinor)->toBe(100)
        ->and($e->totalMinor)->toBe(1100)
        ->and($snapshot->draft->lines[0]->evidence->taxRateBasisPoints)->toBe(1000);
});

it('rounds tax once per rate group: two ¥1,234 lines at 8% owe ¥197, not ¥198', function () {
    tprSetting();
    // Same rate, two separate menu lines — per-line rounding would give 99+99.
    $snapshot = tprResolve([[tprMenuLine(1234, 8), 1], [tprMenuLine(1234, 8), 1]]);

    expect($snapshot->draft->pricingEvidence->taxMinor)->toBe(197)
        ->and(collect($snapshot->draft->lines)->sum(fn ($l) => $l->evidence->taxAmountMinor))->toBe(
            197,
            'The per-line allocation must redistribute the group tax exactly — if the lines sum to 198 the receipt and the order disagree by ¥1.',
        );
});

it('keeps 軽減税率 and standard rate in separate groups', function () {
    tprSetting();
    $snapshot = tprResolve([[tprMenuLine(1000, 8), 1], [tprMenuLine(1000, 10), 1]]);

    $e = $snapshot->draft->pricingEvidence;
    expect($e->subtotalMinor)->toBe(2000)
        ->and($e->taxMinor)->toBe(180)
        ->and($e->totalMinor)->toBe(2180);
});

it('honours the branch rounding snapshot at the 123.4 boundary', function (string $mode, int $expectedTax) {
    tprSetting(['tax_rounding_mode' => $mode]);
    $snapshot = tprResolve([[tprMenuLine(1234, 10), 1]]);

    expect($snapshot->draft->pricingEvidence->taxMinor)->toBe($expectedTax)
        ->and($snapshot->draft->pricingEvidence->taxRoundingMode)->toBe($mode);
})->with([
    'round' => ['round', 123],
    'ceil' => ['ceil', 124],
    'floor' => ['floor', 123],
]);

it('bills the taxed service charge scenario at exactly ¥1,210', function () {
    tprSetting(['service_charge_rate' => 10, 'service_charge_tax_rate' => 10]);
    $snapshot = tprResolve([[tprMenuLine(1000, 10), 1]]);

    $e = $snapshot->draft->pricingEvidence;
    expect($e->serviceChargeMinor)->toBe(100)
        ->and($e->taxMinor)->toBe(110)
        ->and($e->totalMinor)->toBe(1210)
        ->and($snapshot->draft->serviceCharge->taxAmountMinor)->toBe(10);
});

it('does not add 内税 on top: a ¥1,100 tax-included line still totals ¥1,100', function () {
    tprSetting(['prices_include_tax' => true]);
    $snapshot = tprResolve([[tprMenuLine(1100, 10), 1]]);

    $e = $snapshot->draft->pricingEvidence;
    expect($e->taxIncluded)->toBeTrue()
        ->and($e->subtotalMinor)->toBe(1100)
        ->and($e->taxMinor)->toBe(100)
        ->and($e->totalMinor)->toBe(
            1100,
            'Tax-included mode double-charged: the customer agreed to ¥1,100 with tax inside, so billing more is charging the tax twice.',
        );
});

it('bakes an active promotion into the unit price and keeps the strikethrough', function () {
    tprSetting();
    $line = tprMenuLine(1000, 10);
    MenuPromotion::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'discount_percent' => 15,
        'applies_to' => 'all_items',
        'stacking_mode' => 'stackable_with_coupons',
        'is_active' => true,
        'weekdays' => [],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);

    $snapshot = tprResolve([[$line, 1]]);

    $payload = $snapshot->draft->lines[0];
    expect($payload->unitPriceMinor)->toBe(850)
        ->and($payload->evidence->originalUnitPriceMinor)->toBe(1000)
        ->and($payload->evidence->promotionId)->not->toBeNull()
        ->and($snapshot->draft->pricingEvidence->subtotalMinor)->toBe(850);
});

// ---------------------------------------------------------------------------
// LIVE PARITY — typed resolver vs the actual legacy engine, same fixture
// ---------------------------------------------------------------------------

it('matches the legacy engine field-by-field', function (array $settings, callable $makeLines) {
    tprSetting($settings);
    $lines = $makeLines();

    tprAssertParity(tprResolve($lines), tprLegacyOrder($lines));
})->with([
    'single 10% line' => [[], fn () => [[tprMenuLine(1000, 10), 1]]],
    'group rounding boundary (2× ¥1,234 @8%)' => [[], fn () => [[tprMenuLine(1234, 8), 1], [tprMenuLine(1234, 8), 1]]],
    'mixed 8%/10% with quantities' => [[], fn () => [[tprMenuLine(780, 8), 2], [tprMenuLine(1500, 10), 1]]],
    'taxed service charge' => [['service_charge_rate' => 10, 'service_charge_tax_rate' => 10], fn () => [[tprMenuLine(1000, 10), 1]]],
    'ceil rounding' => [['tax_rounding_mode' => 'ceil'], fn () => [[tprMenuLine(1234, 10), 1]]],
    'floor rounding' => [['tax_rounding_mode' => 'floor'], fn () => [[tprMenuLine(1234, 10), 1]]],
    'tax included' => [['prices_include_tax' => true], fn () => [[tprMenuLine(1100, 10), 1]]],
    'everything at once' => [
        ['service_charge_rate' => 10, 'service_charge_tax_rate' => 10, 'tax_rounding_mode' => 'floor'],
        fn () => [[tprMenuLine(780, 8), 3], [tprMenuLine(1234, 10), 2], [tprMenuLine(999, 8), 1]],
    ],
]);

// ---------------------------------------------------------------------------
// Guard rails — wrong inputs fail loudly and specifically, never misprice
// ---------------------------------------------------------------------------

it('refuses a menu line from another branch — stale menu on the customer device', function () {
    tprSetting();
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $foreignMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id,
        'status' => 'Active',
    ]);
    $this->menu = $foreignMenu;
    $foreignLine = tprMenuLine(1000, 10);
    $this->menu = Menu::query()->where('branch_id', $this->branch->id)->firstOrFail();

    expect(fn () => tprResolve([[$foreignLine, 1]]))
        ->toThrow(InvalidArgumentException::class, 'not an active menu line of branch');
});

it('refuses a deactivated product instead of pricing it', function () {
    tprSetting();
    $line = tprMenuLine(1000, 10);
    $line->productSku->product->update(['status' => 'inactive']);

    expect(fn () => tprResolve([[$line, 1]]))
        ->toThrow(InvalidArgumentException::class, 'not sellable');
});

/**
 * Attach a priced topping group to the line's product and return the
 * (topping item, topping sku) pair. Mirrors CustomerOrderToppingsPersistTest.
 */
function tprToppingFor(MenuProductSku $line, float $extraPrice, int $minSelect = 0, bool $isDefault = false): array
{
    $group = ToppingGroup::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'is_active' => true,
        'min_select' => $minSelect,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ]);
    $toppingProduct = Product::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
    ]);
    $toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => true,
        'selling_price' => $extraPrice,
    ]);
    $item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $group->id,
        'product_id' => $toppingProduct->id,
        'is_default' => $isDefault,
        'sort_order' => 0,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $item->id,
        'product_sku_id' => $toppingSku->id,
        'extra_price' => $extraPrice,
    ]);
    $line->productSku->product->toppingGroups()->attach($group->id, ['sort_order' => 0]);

    return [$item, $toppingSku];
}

/** @param list<array{0: string, 1: string, 2: int}> $toppings (itemId, skuId, qty) */
function tprResolveWithToppings(MenuProductSku $line, int $qty, array $toppings): TrustedOrderSnapshot
{
    $selection = new OrderSelectionPayload([
        new OrderLineSelectionPayload((string) Str::uuid(), (string) $line->id, $qty, array_map(
            fn (array $t) => new OrderToppingSelectionPayload($t[0], $t[1], $t[2]),
            $toppings,
        )),
    ]);
    $command = new CreateOrderCommand(
        new MutationContext(test()->orgId, null, (string) Str::uuid(), (string) Str::uuid(), 1),
        (string) Str::uuid(),
        (string) test()->branch->id,
        $selection,
        $selection->fingerprint(),
    );

    return app(CustomerOrderPricingResolution::class)->resolveOrder($command);
}

it('prices a topping into the taxable base: ¥1,000 dish + ¥200 topping @10% = ¥1,320', function () {
    tprSetting();
    $line = tprMenuLine(1000, 10);
    [$item, $sku] = tprToppingFor($line, 200);

    $snapshot = tprResolveWithToppings($line, 1, [[(string) $item->id, (string) $sku->id, 1]]);

    $e = $snapshot->draft->pricingEvidence;
    $payload = $snapshot->draft->lines[0];
    expect($e->subtotalMinor)->toBe(1200)
        ->and($e->taxMinor)->toBe(120)
        ->and($e->totalMinor)->toBe(1320)
        ->and($payload->evidence->toppingSubtotalMinor)->toBe(200)
        ->and($payload->toppings)->toHaveCount(1)
        ->and($payload->toppings[0]->unitPriceMinor)->toBe(200)
        ->and($payload->toppings[0]->productSkuId)->toBe((string) $sku->id);
});

it('matches the legacy engine on a topping order, minor unit for minor unit', function () {
    tprSetting();
    $line = tprMenuLine(1000, 10);
    [$item, $sku] = tprToppingFor($line, 150);
    $toppings = [[(string) $item->id, (string) $sku->id, 1]];

    $snapshot = tprResolveWithToppings($line, 2, $toppings);

    // Legacy half: same fixture through create → addItems → refresh.
    $service = app(CustomerOrderService::class);
    $order = $service->create([
        'order_type' => 'spot',
        'status' => CustomerOrderStatusEnum::Open->value,
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $service->addItems($order, ['items' => [[
        'product_sku_id' => (string) $line->product_sku_id,
        'menu_product_sku_id' => (string) $line->id,
        'quantity' => 2,
        'toppings' => [[
            'topping_group_item_id' => (string) $item->id,
            'product_sku_id' => (string) $sku->id,
            'quantity' => 1,
        ]],
    ]]]);
    $service->refreshOrderTotals($order->fresh('items'));
    $order->refresh();

    tprAssertParity($snapshot, $order->fresh('items'));
});

it('auto-fills the default of a mandatory group the customer skipped, like legacy does', function () {
    tprSetting();
    $line = tprMenuLine(1000, 10);
    tprToppingFor($line, 100, minSelect: 1, isDefault: true);

    // Customer sent NO toppings; the mandatory group's flagged default (¥100)
    // must be injected — otherwise typed orders lose the required side dish
    // that every legacy order carries.
    $snapshot = tprResolveWithToppings($line, 1, []);

    expect($snapshot->draft->pricingEvidence->subtotalMinor)->toBe(1100)
        ->and($snapshot->draft->lines[0]->toppings)->toHaveCount(1)
        ->and($snapshot->draft->lines[0]->evidence->toppingSubtotalMinor)->toBe(100);
});
