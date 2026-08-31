<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\TaxType;
use App\Services\Customer\CustomerMenuService;
use App\Services\Workstation\MenuCatalogReplicaBuilder;
use Illuminate\Support\Str;

/**
 * #1180 — floating sections (seasonal/promo spotlights) must reach the
 * workstation's offline catalog replica, and must carry the SAME collapsed
 * consumption-tax type the online customer menu resolves for the same product.
 *
 * The tax assertions here are deliberately written against
 * CustomerMenuService's OWN output rather than against a hard-coded id: the
 * point is not "the column is populated", it is "Cloud online and Cloud offline
 * cannot disagree". A workstation that re-walked the tiers in Go, or a replica
 * that shipped the raw override instead of the collapsed value, would print one
 * rate on the customer's receipt and book another.
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
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    // 標準 10% is the brand default (resolver's last tier), 軽減 8% the override.
    $this->standard = TaxType::factory()->standard()->asDefault()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->reduced = TaxType::factory()->reduced()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->builder = app(MenuCatalogReplicaBuilder::class);

    // A head menu is the precondition for BOTH paths (see the builder docblock),
    // so give the branch one ordinary published menu line.
    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
        'priority' => 1,
    ]);

    $this->makeProduct = function (?string $taxTypeId, string $name, float $price = 1000): array {
        $product = Product::factory()->active()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'product_type_id' => $this->productType->id,
            'name' => $name,
            'is_hidden' => false,
            'tax_type_id' => $taxTypeId,
        ]);
        $sku = ProductSku::factory()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'selling_price' => $price,
        ]);

        return compact('product', 'sku');
    };

    /** Put a product on the head menu so it is reachable the ordinary way too. */
    $this->putOnMenu = function (Product $product, ProductSku $sku, float $price = 1000): void {
        $menuProduct = MenuProduct::factory()->create([
            'menu_id' => $this->menu->id,
            'product_id' => $product->id,
            'is_active' => true,
            'display_order' => 0,
        ]);
        MenuProductSku::factory()->create([
            'menu_product_id' => $menuProduct->id,
            'product_sku_id' => $sku->id,
            'selling_price' => $price,
            'is_active' => true,
        ]);
    };

    /**
     * An always-on spotlight holding one product at a promo price.
     * `$overrideTaxTypeId` is the FloatingSectionProduct tier-1 override.
     */
    $this->addFloating = function (
        Product $product,
        ProductSku $sku,
        float $promoPrice = 650,
        ?string $overrideTaxTypeId = null,
        string $name = 'Happy hour',
        string $startTime = '00:00:00',
        string $endTime = '23:59:59',
        int $daysOfWeek = 127,
    ): array {
        $section = FloatingSection::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'name' => $name,
            'priority' => 0,
            'is_active' => true,
            'start_date' => null,
            'end_date' => null,
        ]);
        $schedule = $section->schedules()->create([
            'start_time' => $startTime,
            'end_time' => $endTime,
            'days_of_week' => $daysOfWeek,
            'is_active' => true,
            'priority' => 0,
        ]);
        $sectionProduct = $section->products()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'display_order' => 1,
            'tax_type_id' => $overrideTaxTypeId,
        ]);
        $sectionSku = $sectionProduct->skus()->create([
            'product_sku_id' => $sku->id,
            'selling_price' => $promoPrice,
            'is_active' => true,
            'is_price_overridden' => true,
        ]);

        return compact('section', 'schedule', 'sectionProduct', 'sectionSku');
    };

    /** The online (cloud) floating item CustomerMenuService serves right now. */
    $this->onlineFloatingItem = function (string $sectionId): array {
        $menu = app(CustomerMenuService::class)->getMenuForBranch($this->branch->id);
        expect($menu)->not->toBeNull();

        $category = collect($menu['categories'])
            ->firstWhere('id', 'floating-section-'.$sectionId);
        expect($category)->not->toBeNull();

        return $category['items'][0];
    };

    /** The replica row for one floating-section product. */
    $this->replicaFloatingProduct = function (string $sectionProductId): array {
        $row = collect($this->builder->buildForBranch($this->branch->id)['floating_section_products'])
            ->firstWhere('id', $sectionProductId);
        expect($row)->not->toBeNull();

        return $row;
    };
});

it('collapses the floating tax tier to the SAME value the online menu resolves — override wins', function () {
    // Product is 標準 10%, the spotlight overrides it to 軽減 8%.
    $seed = ($this->makeProduct)($this->standard->id, 'Onigiri');
    ($this->putOnMenu)($seed['product'], $seed['sku']);
    $floating = ($this->addFloating)(
        $seed['product'], $seed['sku'], overrideTaxTypeId: $this->reduced->id
    );

    $online = ($this->onlineFloatingItem)($floating['section']->id);
    $replica = ($this->replicaFloatingProduct)($floating['sectionProduct']->id);

    expect($replica['tax_type_id'])->toBe($online['tax_type_id'])
        // …and it really is the override, not the product's own type. Without
        // this the assertion above would pass on two identical wrong answers.
        ->and($replica['tax_type_id'])->toBe($this->reduced->id)
        ->and($replica['tax_type_id'])->not->toBe($this->standard->id);
});

it('inherits the product tax type when the spotlight sets no override, matching online', function () {
    $seed = ($this->makeProduct)($this->reduced->id, 'Bento');
    ($this->putOnMenu)($seed['product'], $seed['sku']);
    $floating = ($this->addFloating)($seed['product'], $seed['sku'], overrideTaxTypeId: null);

    $online = ($this->onlineFloatingItem)($floating['section']->id);
    $replica = ($this->replicaFloatingProduct)($floating['sectionProduct']->id);

    expect($replica['tax_type_id'])->toBe($online['tax_type_id'])
        ->and($replica['tax_type_id'])->toBe($this->reduced->id);
});

it('emits null when neither tier resolves, so the device falls through to its own defaults', function () {
    // Null is NOT "no tax": it means inherit. The workstation's resolver then
    // continues to shop_settings.default_tax_type_id → brand default, exactly
    // as TaxResolver does on Cloud. Emitting the brand default here instead
    // would freeze a value the shop can still change.
    $seed = ($this->makeProduct)(null, 'Mystery box');
    ($this->putOnMenu)($seed['product'], $seed['sku']);
    $floating = ($this->addFloating)($seed['product'], $seed['sku'], overrideTaxTypeId: null);

    $online = ($this->onlineFloatingItem)($floating['section']->id);
    $replica = ($this->replicaFloatingProduct)($floating['sectionProduct']->id);

    expect($replica['tax_type_id'])->toBe($online['tax_type_id'])
        ->and($replica['tax_type_id'])->toBeNull();
});

it('ships a spotlight-only product and its SKU so an offline register can sell it at all', function () {
    // The whole point of a spotlight: this product is in NO menu, so before
    // #1180 the workstation had no products/skus row for it and pos-web could
    // not ring it up while the internet was down.
    $onMenu = ($this->makeProduct)($this->standard->id, 'Regular item');
    ($this->putOnMenu)($onMenu['product'], $onMenu['sku']);

    $spotlightOnly = ($this->makeProduct)($this->reduced->id, 'Seasonal special', 1200);
    $floating = ($this->addFloating)($spotlightOnly['product'], $spotlightOnly['sku'], promoPrice: 900);

    $out = $this->builder->buildForBranch($this->branch->id);

    expect(collect($out['products'])->pluck('id')->all())->toContain($spotlightOnly['product']->id)
        ->and(collect($out['skus'])->pluck('id')->all())->toContain($spotlightOnly['sku']->id)
        // It is NOT smuggled into the menu arrays — pos-web browses menus from
        // pos_menu_products, and a phantom row there would invent a menu line.
        ->and(collect($out['menu_products'])->pluck('product_id')->all())
        ->not->toContain($spotlightOnly['product']->id);

    $section = collect($out['floating_sections'])->firstWhere('id', $floating['section']->id);
    expect($section)->not->toBeNull()
        ->and($section['name'])->toBe('Happy hour')
        ->and($section['is_active'])->toBeTrue();
});

it('keeps the promo price off skus.selling_price', function () {
    // skus.selling_price is the MENU price. Writing the promo into it would
    // re-price the same SKU sold from an ordinary menu — an offline
    // mispricing that no amount of syncing would explain afterwards.
    $seed = ($this->makeProduct)($this->standard->id, 'Shared SKU', 1000);
    ($this->putOnMenu)($seed['product'], $seed['sku'], 1000);
    $floating = ($this->addFloating)($seed['product'], $seed['sku'], promoPrice: 650);

    $out = $this->builder->buildForBranch($this->branch->id);

    $sku = collect($out['skus'])->firstWhere('id', $seed['sku']->id);
    $floatingSku = collect($out['floating_section_product_skus'])
        ->firstWhere('id', $floating['sectionSku']->id);

    expect($sku['selling_price'])->toBe(1000)
        ->and($floatingSku['selling_price'])->toBe(650)
        ->and($floatingSku['product_sku_id'])->toBe($seed['sku']->id)
        ->and($floatingSku['floating_section_product_id'])->toBe($floating['sectionProduct']->id);
});

it('ships the schedule window raw, including a section that is closed right now', function () {
    // Cloud must NOT pre-filter "live right now": the workstation runs for
    // hours between pulls (and through the night offline), so the window is
    // its decision, made against its own clock.
    $seed = ($this->makeProduct)($this->standard->id, 'Breakfast set');
    ($this->putOnMenu)($seed['product'], $seed['sku']);
    $floating = ($this->addFloating)(
        $seed['product'],
        $seed['sku'],
        name: 'Dead hour',
        // Sunday-only, one-minute window — closed on almost every run.
        startTime: '03:00:00',
        endTime: '03:01:00',
        daysOfWeek: 1,
    );

    $out = $this->builder->buildForBranch($this->branch->id);

    $section = collect($out['floating_sections'])->firstWhere('id', $floating['section']->id);
    $schedule = collect($out['floating_section_schedules'])
        ->firstWhere('id', $floating['schedule']->id);

    expect($section)->not->toBeNull()
        ->and($schedule)->not->toBeNull()
        ->and($schedule['floating_section_id'])->toBe($floating['section']->id)
        ->and($schedule['days_of_week'])->toBe(1)
        ->and($schedule['start_time'])->toStartWith('03:00')
        ->and($schedule['end_time'])->toStartWith('03:01')
        ->and($schedule['is_active'])->toBeTrue();
});

it('drops a floating row whose product is soft-deleted (SQLite FK 787 guard)', function () {
    // Same hazard the menu_products block documents: keeping the floating row
    // while the products row is filtered out makes the workstation insert a
    // child referencing a missing parent, and the WHOLE catalog transaction
    // aborts — POS then shows no menu at all.
    $live = ($this->makeProduct)($this->standard->id, 'Live');
    ($this->putOnMenu)($live['product'], $live['sku']);
    $gone = ($this->makeProduct)($this->standard->id, 'Gone');

    $liveFloating = ($this->addFloating)($live['product'], $live['sku'], name: 'Live promo');
    $goneFloating = ($this->addFloating)($gone['product'], $gone['sku'], name: 'Dead promo');
    $gone['product']->delete();

    $out = $this->builder->buildForBranch($this->branch->id);

    $floatingProductIds = collect($out['floating_section_products'])->pluck('id')->all();
    $floatingSkuIds = collect($out['floating_section_product_skus'])->pluck('id')->all();

    expect($floatingProductIds)->toContain($liveFloating['sectionProduct']->id)
        ->and($floatingProductIds)->not->toContain($goneFloating['sectionProduct']->id)
        ->and(collect($out['products'])->pluck('id')->all())->not->toContain($gone['product']->id)
        // The floating sku hangs off the dropped floating product — it must go
        // with it, not linger as an orphan.
        ->and($floatingSkuIds)->not->toContain($goneFloating['sectionSku']->id);
});

it('excludes floating sections belonging to another branch', function () {
    $seed = ($this->makeProduct)($this->standard->id, 'Ours');
    ($this->putOnMenu)($seed['product'], $seed['sku']);
    $ours = ($this->addFloating)($seed['product'], $seed['sku'], name: 'Ours');

    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $theirs = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $otherBranch->id,
        'name' => 'Theirs',
        'is_active' => true,
        'start_date' => null,
        'end_date' => null,
    ]);

    $sectionIds = collect($this->builder->buildForBranch($this->branch->id)['floating_sections'])
        ->pluck('id')->all();

    expect($sectionIds)->toContain($ours['section']->id)
        ->and($sectionIds)->not->toContain($theirs->id);
});

it('exposes the floating arrays in the empty shape so the puller sees a stable contract', function () {
    expect(array_keys($this->builder->emptyShape()))->toContain(
        'floating_sections',
        'floating_section_schedules',
        'floating_section_products',
        'floating_section_product_skus',
        'floating_section_topping_overrides',
    );
});
