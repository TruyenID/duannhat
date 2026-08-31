<?php

/**
 * plan-056 — the POS "Tồn món" screen's Cloud-direct door.
 *
 * Three things this file exists to pin, each of which fails silently if broken:
 *
 *   1. The management read shows TURNED-OFF rows. The ordering read
 *      (`/pos/menus/*`) must keep hiding them. Two endpoints, two answers, one
 *      dataset — a shared code path with a flag is how those get swapped.
 *   2. Writes are SET, not toggle. The workstation replays queued ops
 *      at-least-once; a flip that lands twice puts a sold-out dish back on sale
 *      with nobody watching.
 *   3. The reason field never blocks a toggle. A cashier mid-service taps a
 *      preset chip; "too short" is a validation error that costs service time
 *      and protects nothing.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuAvailabilityEvent;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        'slug' => 'availability-probe',
    ]);
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
        'name' => 'Ann Cashier',
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
        'is_master' => false,
    ]);

    $this->seedDish = function (string $name = 'Phở bò', int $price = 1000): array {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'product_type_id' => $this->productType->id,
            'status' => 'active',
            'is_hidden' => false,
            'name' => $name,
        ]);
        $sku = ProductSku::factory()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'selling_price' => $price,
            'sku' => 'SKU-'.Str::upper(Str::random(4)),
        ]);

        $mpId = (string) Str::uuid();
        DB::table('menu_products')->insert([
            'id' => $mpId,
            'menu_id' => $this->menu->id,
            'product_id' => $product->id,
            'is_active' => true,
            'display_order' => 1,
        ]);
        $mpsId = (string) Str::uuid();
        DB::table('menu_product_skus')->insert([
            'id' => $mpsId,
            'menu_product_id' => $mpId,
            'product_sku_id' => $sku->id,
            'selling_price' => $price,
            'is_active' => true,
        ]);

        return ['product' => $product, 'sku' => $sku, 'mpId' => $mpId, 'mpsId' => $mpsId];
    };

    $this->asStaff = fn () => $this->actingAs($this->user)
        ->withHeader('X-Shop-Slug', $this->branch->slug);
});

// =========================================================================
//  Read — the management shape shows what the ordering shape hides
// =========================================================================

it('lists a turned-off dish with its reason', function () {
    $dish = ($this->seedDish)();
    DB::table('menu_products')->where('id', $dish['mpId'])->update([
        'is_active' => false,
        'disabled_reason' => 'Hết hàng',
        'disabled_by_name' => 'Ann Cashier',
        'disabled_at' => now(),
    ]);

    $body = ($this->asStaff)()
        ->getJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}")
        ->assertOk()
        ->json('data');

    expect($body['products'])->toHaveCount(1);
    expect($body['products'][0]['is_active'])->toBeFalse()
        ->and($body['products'][0]['disabled_reason'])->toBe('Hết hàng')
        ->and($body['products'][0]['disabled_by_name'])->toBe('Ann Cashier');
});

it('keeps the ORDERING endpoint hiding what the management endpoint shows', function () {
    // The contract of the whole plan in one test. Same dish, same request,
    // two namespaces: the cart picker must not learn about sold-out food.
    $dish = ($this->seedDish)();
    DB::table('menu_products')->where('id', $dish['mpId'])->update(['is_active' => false]);

    $managed = ($this->asStaff)()
        ->getJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}")
        ->assertOk()
        ->json('data.products');

    $ordering = ($this->asStaff)()
        ->getJson("/api/v1/pos/menus/{$this->menu->id}/products")
        ->assertOk()
        ->json('data');

    expect($managed)->toHaveCount(1);
    expect(collect($ordering)->firstWhere('id', $dish['mpId'])['is_active'] ?? null)
        ->not->toBeTrue();
});

it('exposes the menu_product_sku id, not the catalog sku id, as the write address', function () {
    $dish = ($this->seedDish)();

    $skus = ($this->asStaff)()
        ->getJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}")
        ->assertOk()
        ->json('data.products.0.skus');

    expect($skus)->toHaveCount(1)
        ->and($skus[0]['id'])->toBe($dish['mpsId'])
        ->and($skus[0]['product_sku_id'])->toBe($dish['sku']->id);
});

it('serves price as read-only context and offers no way to write it', function () {
    $dish = ($this->seedDish)(price: 1000);
    DB::table('menu_product_skus')->where('id', $dish['mpsId'])
        ->update(['selling_price' => 1200, 'is_price_overridden' => true]);

    $sku = ($this->asStaff)()
        ->getJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}")
        ->assertOk()
        ->json('data.products.0.skus.0');

    // Cast before comparing: JSON has no int/float distinction that a
    // TypeScript client can observe (one `number` type), so pinning PHP's
    // encoder choice here would be a test that fails on a currency change
    // rather than on a defect.
    expect((float) $sku['selling_price'])->toBe(1200.0)
        ->and((float) $sku['default_price'])->toBe(1000.0)
        ->and($sku['is_price_overridden'])->toBeTrue();

    // …and no route in this namespace accepts a price. Asserted against the
    // router rather than by trying one URL, so a future endpoint cannot slip
    // in unnoticed.
    $writable = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => str_contains($r->uri(), 'pos/menu-availability'))
        ->filter(fn ($r) => array_intersect(['POST', 'PUT', 'PATCH'], $r->methods()) !== [])
        ->map(fn ($r) => $r->uri())
        ->values();

    expect($writable->filter(fn ($u) => str_contains($u, 'price')))->toBeEmpty();
});

// =========================================================================
//  Write — SET, and idempotent under replay
// =========================================================================

it('turns a dish off with a reason and stamps who and when', function () {
    $dish = ($this->seedDish)();

    ($this->asStaff)()
        ->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
            'is_active' => false,
            'reason' => 'Hết hàng',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.disabled_reason', 'Hết hàng');

    $row = MenuProduct::find($dish['mpId']);
    expect($row->is_active)->toBeFalse()
        ->and($row->disabled_reason)->toBe('Hết hàng')
        ->and($row->disabled_by_name)->toBe('Ann Cashier')
        ->and($row->disabled_at)->not->toBeNull();
});

it('clears the reason when the dish is switched back on', function () {
    // A leftover "hết hàng" on a dish that IS on sale reads as a bug in the
    // shop's stock, not in us — the three columns move as one unit.
    $dish = ($this->seedDish)();

    ($this->asStaff)()->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
        'is_active' => false, 'reason' => 'Hết hàng',
    ])->assertOk();

    ($this->asStaff)()->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
        'is_active' => true,
    ])->assertOk();

    $row = MenuProduct::find($dish['mpId']);
    expect($row->is_active)->toBeTrue()
        ->and($row->disabled_reason)->toBeNull()
        ->and($row->disabled_at)->toBeNull()
        ->and($row->disabled_by_name)->toBeNull();
});

it('is idempotent — the same write twice leaves the same state and ONE event', function () {
    // The replay guarantee. A toggle endpoint would flip back here; and a log
    // that counted the retry would inflate "how often was this out of stock".
    $dish = ($this->seedDish)();

    foreach ([1, 2, 3] as $_) {
        ($this->asStaff)()->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
            'is_active' => false, 'reason' => 'Hết hàng',
        ])->assertOk();
    }

    expect(MenuProduct::find($dish['mpId'])->is_active)->toBeFalse()
        ->and(MenuAvailabilityEvent::where('entity_id', $dish['mpId'])->count())->toBe(1);
});

it('#3149 — lượt ghi LẶP một giây sau vẫn KHÔNG đẻ thêm sự kiện', function () {
    // Bài "is idempotent" ngay trên đã phát biểu đúng tính chất này, nhưng nó
    // bắn ba lượt PUT liên tiếp nên thường nằm gọn trong MỘT giây — và tính chất
    // chỉ vỡ khi hai lượt vắt qua ranh giây. Nó vì thế đỏ theo TẢI MÁY: xanh khi
    // chạy riêng, đỏ khi `pest --parallel` làm request chậm đi. Một PR không
    // liên quan nhận cái đỏ đó và mất một lượt đi truy chính mình (#3146).
    //
    // Bài này dời đồng hồ thay vì hy vọng vào thời điểm, nên nó đo đúng thứ nó
    // nói và cho cùng một câu trả lời trên mọi máy.
    $dish = ($this->seedDish)();

    $this->travelTo(now()->startOfSecond());

    ($this->asStaff)()->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
        'is_active' => false, 'reason' => 'Hết hàng',
    ])->assertOk();

    $stampedAt = MenuProduct::find($dish['mpId'])->disabled_at;

    $this->travel(1)->seconds();

    ($this->asStaff)()->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
        'is_active' => false, 'reason' => 'Hết hàng',
    ])->assertOk();

    expect(MenuAvailabilityEvent::where('entity_id', $dish['mpId'])->count())->toBe(1)
        // Và dấu thời gian giữ nguyên: "đã tắt bao lâu" phải đo từ lúc nó TẮT,
        // không phải từ lượt phát lại gần nhất. Thiếu vế này thì một bản sửa chỉ
        // chặn ghi sự kiện mà vẫn dời `disabled_at` vẫn đi qua.
        ->and(MenuProduct::find($dish['mpId'])->disabled_at->equalTo($stampedAt))->toBeTrue();
});

it('#3149 — nhưng ĐỔI LÝ DO thì VẪN là một thay đổi thật', function () {
    // Vế ngược, và nó quan trọng ngang vế trên: một bản sửa quá tay — "đã tắt
    // rồi thì thôi, không ghi gì nữa" — sẽ nuốt luôn lượt sửa lý do, và quán mất
    // đường ghi nhận vì sao món đổi trạng thái.
    $dish = ($this->seedDish)();

    ($this->asStaff)()->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
        'is_active' => false, 'reason' => 'Hết hàng',
    ])->assertOk();

    ($this->asStaff)()->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
        'is_active' => false, 'reason' => 'Hỏng bếp',
    ])->assertOk();

    expect(MenuAvailabilityEvent::where('entity_id', $dish['mpId'])->count())->toBe(2)
        ->and(MenuProduct::find($dish['mpId'])->disabled_reason)->toBe('Hỏng bếp');
});

it('turns a single VARIANT off without touching the dish', function () {
    $dish = ($this->seedDish)();

    ($this->asStaff)()
        ->putJson("/api/v1/pos/menu-availability/skus/{$dish['mpsId']}", [
            'is_active' => false, 'reason' => 'Hết size L',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect(MenuProductSku::find($dish['mpsId'])->is_active)->toBeFalse()
        ->and(MenuProduct::find($dish['mpId'])->is_active)->toBeTrue();
});

it('records one event per real change, with the source and the actor', function () {
    $dish = ($this->seedDish)();

    ($this->asStaff)()->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
        'is_active' => false, 'reason' => 'Hết hàng',
    ])->assertOk();

    $event = MenuAvailabilityEvent::where('entity_id', $dish['mpId'])->sole();
    // The model casts both columns to their generated enums, so compare values.
    expect($event->entity_type->value)->toBe('menu_product')
        ->and($event->source->value)->toBe('pos')
        ->and($event->is_active)->toBeFalse()
        ->and($event->reason)->toBe('Hết hàng')
        ->and($event->actor_name)->toBe('Ann Cashier')
        ->and((string) $event->acted_by_user_id)->toBe((string) $this->user->getKey())
        ->and((string) $event->branch_id)->toBe((string) $this->branch->id);
});

// =========================================================================
//  Reason validation — lenient by design
// =========================================================================

it('accepts a one-character reason', function () {
    $dish = ($this->seedDish)();

    ($this->asStaff)()
        ->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
            'is_active' => false, 'reason' => 'X',
        ])
        ->assertOk()
        ->assertJsonPath('data.disabled_reason', 'X');
});

it('accepts a missing reason rather than demanding one', function () {
    // admin-web's existing toggle sends none, and a cashier in a hurry should
    // not be stopped either. The screen asks; the API does not enforce.
    $dish = ($this->seedDish)();

    ($this->asStaff)()
        ->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", ['is_active' => false])
        ->assertOk();

    expect(MenuProduct::find($dish['mpId'])->is_active)->toBeFalse();
});

it('rejects a reason longer than the column, and nothing shorter', function () {
    $dish = ($this->seedDish)();

    ($this->asStaff)()
        ->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}", [
            'is_active' => false, 'reason' => str_repeat('a', 256),
        ])
        ->assertStatus(422);
});

// =========================================================================
//  Bulk
// =========================================================================

it('turns a whole section off and reports how many rows actually changed', function () {
    $sectionId = (string) Str::uuid();
    DB::table('menu_sections')->insert([
        'id' => $sectionId,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'name' => 'Món chính',
    ]);
    DB::table('menu_menu_sections')->insert([
        'menu_id' => $this->menu->id,
        'menu_section_id' => $sectionId,
        'display_order' => 1,
    ]);

    $a = ($this->seedDish)('A');
    $b = ($this->seedDish)('B');
    DB::table('menu_products')->whereIn('id', [$a['mpId'], $b['mpId']])
        ->update(['menu_section_id' => $sectionId]);
    // B is already off — the count must report REAL changes, because the toast
    // says "đã tắt N món" and a padded number teaches staff to distrust it.
    DB::table('menu_products')->where('id', $b['mpId'])->update(['is_active' => false]);

    ($this->asStaff)()
        ->postJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}/sections/{$sectionId}/bulk", [
            'is_active' => false, 'reason' => 'Hết nguyên liệu',
        ])
        ->assertOk()
        ->assertJsonPath('updated', 1);

    expect(MenuProduct::find($a['mpId'])->is_active)->toBeFalse()
        ->and(MenuProduct::find($a['mpId'])->disabled_reason)->toBe('Hết nguyên liệu');
});

// =========================================================================
//  Tenancy
// =========================================================================

it('404s a dish belonging to another shop', function () {
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);
    $otherMenu = Menu::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
        'branch_id' => $otherBranch->id,
        'status' => 'Active',
        'is_master' => false,
    ]);
    $foreignProduct = Product::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
        'status' => 'active',
        'is_hidden' => false,
    ]);
    $foreignMpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $foreignMpId,
        'menu_id' => $otherMenu->id,
        'product_id' => $foreignProduct->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    ($this->asStaff)()
        ->putJson("/api/v1/pos/menu-availability/products/{$foreignMpId}", ['is_active' => false])
        ->assertNotFound();

    expect(MenuProduct::find($foreignMpId)->is_active)->toBeTrue();
});

// =========================================================================
//  Toppings
// =========================================================================

/** Attach a topping group with one item to a dish's product. */
function seedToppingFor(string $productId, string $orgId, string $brandId): array
{
    $groupId = (string) Str::uuid();
    DB::table('topping_groups')->insert([
        'id' => $groupId,
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'name' => 'Topping phở',
        'selection_type' => 'multiple',
        'is_active' => true,
    ]);
    DB::table('product_topping_groups')->insert([
        'product_id' => $productId,
        'topping_group_id' => $groupId,
        'sort_order' => 1,
    ]);

    $toppingProduct = Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Trứng chần',
    ]);
    $itemId = (string) Str::uuid();
    DB::table('topping_group_items')->insert([
        'id' => $itemId,
        'topping_group_id' => $groupId,
        'product_id' => $toppingProduct->id,
        'sort_order' => 1,
        'is_default' => false,
    ]);

    return ['groupId' => $groupId, 'itemId' => $itemId, 'toppingProduct' => $toppingProduct];
}

it('carries the add-on price of each topping variant', function () {
    // The expandable BIẾN THỂ · GIÁ BÁN KÈM table on the POS screen, and the
    // reason it can exist at all: the two servers must agree on this shape.
    // The workstation has emitted `skus` on a topping item since plan-056;
    // Cloud emitting a different set of keys is the same class of divergence
    // that once rendered every topping as switched off on the LAN.
    $dish = ($this->seedDish)();
    $topping = seedToppingFor($dish['product']->id, $this->orgId, $this->brand->id);

    $sku = ProductSku::factory()->create([
        'product_id' => $topping['toppingProduct']->id,
        'sku' => 'TOP-TC-L',
    ]);
    DB::table('topping_group_item_skus')->insert([
        'id' => (string) Str::uuid(),
        'topping_group_item_id' => $topping['itemId'],
        'product_sku_id' => $sku->id,
        'extra_price' => 120,
    ]);

    $items = ($this->asStaff)()
        ->getJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}")
        ->assertOk()
        ->json('data.products.0.topping_groups.0.items');

    expect($items[0]['skus'])->toHaveCount(1)
        ->and($items[0]['skus'][0]['product_sku_id'])->toBe((string) $sku->id)
        ->and($items[0]['skus'][0]['sku_code'])->toBe('TOP-TC-L')
        // A STRING, matching the workstation byte for byte. A number here and
        // the client's one coercion becomes a no-op on one transport only.
        ->and($items[0]['skus'][0]['extra_price'])->toBe('120');
});

it('lists toppings with their on/off state, including hidden ones', function () {
    // A topping the shop cannot see is one it can never switch back on — the
    // same one-way door the dish level exists to avoid.
    $dish = ($this->seedDish)();
    $topping = seedToppingFor($dish['product']->id, $this->orgId, $this->brand->id);

    $body = ($this->asStaff)()
        ->getJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}")
        ->assertOk()
        ->json('data.products.0.topping_groups');

    expect($body)->toHaveCount(1)
        ->and($body[0]['items'])->toHaveCount(1)
        ->and($body[0]['items'][0]['name'])->toBe('Trứng chần')
        ->and($body[0]['items'][0]['is_active'])->toBeTrue();
});

it('hides one topping without touching the shop prices of its siblings', function () {
    // THE reason this does not reuse the admin sync endpoint. That endpoint
    // DELETEs every override of the (menu_product, topping_group) pair and
    // rewrites the payload; a POS toggle sending only its own change would take
    // the shop's topping PRICES with it.
    $dish = ($this->seedDish)();
    $topping = seedToppingFor($dish['product']->id, $this->orgId, $this->brand->id);

    // A sibling override that prices a DIFFERENT topping item in the same group.
    $siblingProduct = Product::factory()->create([
        'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
        'status' => 'active', 'is_hidden' => false, 'name' => 'Thịt thêm',
    ]);
    $siblingItemId = (string) Str::uuid();
    DB::table('topping_group_items')->insert([
        'id' => $siblingItemId,
        'topping_group_id' => $topping['groupId'],
        'product_id' => $siblingProduct->id,
        'sort_order' => 2,
        'is_default' => false,
    ]);
    DB::table('menu_product_topping_item_overrides')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $dish['mpId'],
        'topping_group_id' => $topping['groupId'],
        'topping_group_item_id' => $siblingItemId,
        'product_sku_id' => null,
        'is_hidden' => false,
        'override_price' => 5000,
    ]);

    ($this->asStaff)()
        ->putJson("/api/v1/pos/menu-availability/products/{$dish['mpId']}/toppings/{$topping['itemId']}", [
            'is_active' => false,
            'reason' => 'Hết trứng',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    // The hidden one is hidden…
    expect(DB::table('menu_product_topping_item_overrides')
        ->where('topping_group_item_id', $topping['itemId'])
        ->where('is_hidden', true)->exists())->toBeTrue();

    // …and the sibling's PRICE survived untouched. This is the assertion the
    // whole design exists for.
    expect((int) DB::table('menu_product_topping_item_overrides')
        ->where('topping_group_item_id', $siblingItemId)
        ->value('override_price'))->toBe(5000);
});

it('shows a topping again and leaves no empty override row behind', function () {
    // #1203 — an override row carrying neither a price nor a hide still
    // outranks the HQ tier on the LAN, so "empty" is not neutral. Re-enabling
    // must DELETE the row, not blank it.
    $dish = ($this->seedDish)();
    $topping = seedToppingFor($dish['product']->id, $this->orgId, $this->brand->id);

    ($this->asStaff)()->putJson(
        "/api/v1/pos/menu-availability/products/{$dish['mpId']}/toppings/{$topping['itemId']}",
        ['is_active' => false, 'reason' => 'Hết trứng'],
    )->assertOk();

    ($this->asStaff)()->putJson(
        "/api/v1/pos/menu-availability/products/{$dish['mpId']}/toppings/{$topping['itemId']}",
        ['is_active' => true],
    )->assertOk();

    expect(DB::table('menu_product_topping_item_overrides')
        ->where('topping_group_item_id', $topping['itemId'])->count())->toBe(0);
});

it('clears a per-SKU hide too, so showing it again really shows it', function () {
    // The read path hides a topping when ANY shop row says so. Clearing only
    // the wildcard would leave a sku-scoped hide standing: the POS would report
    // the topping back on sale while it stayed invisible.
    $dish = ($this->seedDish)();
    $topping = seedToppingFor($dish['product']->id, $this->orgId, $this->brand->id);

    DB::table('menu_product_topping_item_overrides')->insert([
        'id' => (string) Str::uuid(),
        'menu_product_id' => $dish['mpId'],
        'topping_group_id' => $topping['groupId'],
        'topping_group_item_id' => $topping['itemId'],
        'product_sku_id' => $dish['sku']->id,
        'is_hidden' => true,
        'override_price' => null,
    ]);

    ($this->asStaff)()->putJson(
        "/api/v1/pos/menu-availability/products/{$dish['mpId']}/toppings/{$topping['itemId']}",
        ['is_active' => true],
    )->assertOk();

    expect(DB::table('menu_product_topping_item_overrides')
        ->where('topping_group_item_id', $topping['itemId'])
        ->where('is_hidden', true)->count())->toBe(0);

    $body = ($this->asStaff)()
        ->getJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}")
        ->assertOk()
        ->json('data.products.0.topping_groups.0.items.0');
    expect($body['is_active'])->toBeTrue();
});

it('is idempotent — hiding the same topping three times writes ONE event', function () {
    // Delivery is at-least-once. A replay is not an event: counting it would
    // inflate "how often was this topping out of stock" with retries.
    $dish = ($this->seedDish)();
    $topping = seedToppingFor($dish['product']->id, $this->orgId, $this->brand->id);

    foreach ([1, 2, 3] as $_) {
        ($this->asStaff)()->putJson(
            "/api/v1/pos/menu-availability/products/{$dish['mpId']}/toppings/{$topping['itemId']}",
            ['is_active' => false, 'reason' => 'Hết trứng'],
        )->assertOk();
    }

    expect(DB::table('menu_product_topping_item_overrides')
        ->where('topping_group_item_id', $topping['itemId'])->count())->toBe(1);

    $events = MenuAvailabilityEvent::where('entity_id', $topping['itemId'])->get();
    expect($events)->toHaveCount(1)
        ->and($events->first()->entity_type->value)->toBe('topping_item')
        ->and($events->first()->is_active)->toBeFalse()
        ->and($events->first()->reason)->toBe('Hết trứng');
});

// =========================================================================
//  Option values — "turn off size Lớn for this dish"
// =========================================================================

/**
 * A dish on two option axes: Size(Nhỏ|Lớn) × Cay(Không|Cay) = 4 variants.
 * Returns the menu_product id plus the four menu_product_sku ids keyed by
 * "<size>-<spice>".
 *
 * @return array{mpId: string, skus: array<string, string>, valueIds: array<string, string>}
 */
function seedTwoAxisDish(string $orgId, string $brandId, string $productTypeId, string $menuId): array
{
    $product = Product::factory()->create([
        'organization_id' => $orgId,
        'brand_id' => $brandId,
        'product_type_id' => $productTypeId,
        'status' => 'active',
        'is_hidden' => false,
        'name' => 'Phở bò',
    ]);

    $optionIds = [];
    $valueIds = [];
    foreach ([['Size', ['Nhỏ', 'Lớn']], ['Cay', ['Không', 'Cay']]] as $i => [$optName, $values]) {
        $optionId = (string) Str::uuid();
        $optionIds[$optName] = $optionId;
        DB::table('product_options')->insert([
            'id' => $optionId,
            'product_id' => $product->id,
            'key' => Str::lower($optName),
            'name' => $optName,
            'position' => $i + 1,
            'is_active' => true,
        ]);
        foreach ($values as $j => $label) {
            $valueId = (string) Str::uuid();
            $valueIds[$label] = $valueId;
            DB::table('product_option_values')->insert([
                'id' => $valueId,
                'option_id' => $optionId,
                'value' => Str::slug($label),
                'label' => $label,
                'position' => $j,
                'is_active' => true,
            ]);
        }
    }

    $mpId = (string) Str::uuid();
    DB::table('menu_products')->insert([
        'id' => $mpId,
        'menu_id' => $menuId,
        'product_id' => $product->id,
        'is_active' => true,
        'display_order' => 1,
    ]);

    $skus = [];
    foreach (['Nhỏ', 'Lớn'] as $size) {
        foreach (['Không', 'Cay'] as $spice) {
            $sku = ProductSku::factory()->create([
                'product_id' => $product->id,
                'is_active' => true,
                'selling_price' => 1000,
                'sku' => 'PHO-'.Str::upper(Str::random(4)),
                'option_value1_id' => $valueIds[$size],
                'option_value2_id' => $valueIds[$spice],
            ]);
            // insertOrIgnore + read back: the (menu_product_id, product_sku_id)
            // pair is UNIQUE, and creating the catalog SKU can already have
            // produced the pivot row. Asserting on the row we END UP WITH keeps
            // the fixture honest either way — a blind insert would fail on a
            // row that is exactly what the test wanted.
            DB::table('menu_product_skus')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'menu_product_id' => $mpId,
                'product_sku_id' => $sku->id,
                'selling_price' => 1000,
                'is_active' => true,
            ]);
            // …and force the STARTING state. A pivot row that already existed
            // carries whatever the code that made it decided; these tests are
            // about turning things OFF, so "on" has to be a fact of the fixture
            // rather than a default the fixture happens to inherit.
            DB::table('menu_product_skus')
                ->where('menu_product_id', $mpId)
                ->where('product_sku_id', $sku->id)
                ->update(['is_active' => true, 'selling_price' => 1000]);
            $skus["$size-$spice"] = (string) DB::table('menu_product_skus')
                ->where('menu_product_id', $mpId)
                ->where('product_sku_id', $sku->id)
                ->value('id');
        }
    }

    return ['mpId' => $mpId, 'skus' => $skus, 'valueIds' => $valueIds];
}

it('tells the client which option axes each variant sits on', function () {
    // The client groups the dish's variants by these to offer one switch per
    // option VALUE. Grouping on the LABEL would merge two different values that
    // happen to be spelled the same in different option groups, so the id has
    // to be on the wire alongside the human-readable part.
    $dish = seedTwoAxisDish($this->orgId, $this->brand->id, $this->productType->id, $this->menu->id);

    $skus = ($this->asStaff)()
        ->getJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}")
        ->assertOk()
        ->json('data.products.0.skus');

    expect($skus)->toHaveCount(4);
    foreach ($skus as $sku) {
        expect($sku['options'])->toHaveCount(2)
            ->and($sku['options'][0]['option_name'])->toBe('Size')
            ->and($sku['options'][1]['option_name'])->toBe('Cay')
            ->and($sku['options'][0]['value_id'])->toBeString()
            ->and($sku['options'][0]['value_label'])->toBeIn(['Nhỏ', 'Lớn']);
    }
});

it('turns off every variant carrying one option value, in ONE write', function () {
    // "Hết cỡ Lớn" — two of the four variants, one request, one reason.
    $dish = seedTwoAxisDish($this->orgId, $this->brand->id, $this->productType->id, $this->menu->id);
    $bigOnes = [$dish['skus']['Lớn-Không'], $dish['skus']['Lớn-Cay']];

    $body = ($this->asStaff)()
        ->postJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}/skus/bulk", [
            'is_active' => false,
            'menu_product_sku_ids' => $bigOnes,
            'reason' => 'Hết tô lớn',
        ])
        ->assertOk()
        ->json();

    expect($body['updated'])->toBe(2);

    foreach ($bigOnes as $id) {
        $row = DB::table('menu_product_skus')->where('id', $id)->first();
        expect((bool) $row->is_active)->toBeFalse()
            ->and($row->disabled_reason)->toBe('Hết tô lớn');
    }
    // …and the small ones are untouched. A bulk write that reaches rows the
    // operator did not select is the whole risk this endpoint carries.
    foreach ([$dish['skus']['Nhỏ-Không'], $dish['skus']['Nhỏ-Cay']] as $id) {
        expect((bool) DB::table('menu_product_skus')->where('id', $id)->value('is_active'))->toBeTrue();
    }
});

it('logs one availability event per variant it flipped', function () {
    // The bulk button must leave the same audit trail as flipping each switch
    // by hand — otherwise the cheapest way to turn a menu off is also the one
    // that leaves no record of who did it.
    $dish = seedTwoAxisDish($this->orgId, $this->brand->id, $this->productType->id, $this->menu->id);

    ($this->asStaff)()
        ->postJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}/skus/bulk", [
            'is_active' => false,
            'menu_product_sku_ids' => [$dish['skus']['Lớn-Không'], $dish['skus']['Lớn-Cay']],
            'reason' => 'Hết tô lớn',
        ])->assertOk();

    expect(MenuAvailabilityEvent::query()
        // 'menu_product_sku' spelled out rather than reached through the enum:
        // this file asserts the WIRE value everywhere else too (see the
        // 'menu_product' and 'topping_item' assertions above), so a rename of
        // the enum case cannot quietly change what is stored.
        ->where('entity_type', 'menu_product_sku')
        ->whereIn('entity_id', [$dish['skus']['Lớn-Không'], $dish['skus']['Lớn-Cay']])
        ->count())->toBe(2);
});

it('drops ids that belong to another menu instead of touching them', function () {
    // A queued offline op can sit for hours. It must never reach rows outside
    // the menu it was recorded against — and it must not 404 the whole batch
    // over one stale id either, or forty other variants stay stuck behind it.
    $dish = seedTwoAxisDish($this->orgId, $this->brand->id, $this->productType->id, $this->menu->id);

    $otherMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'active',
    ]);
    $foreign = ($this->seedDish)('Cơm gà');
    DB::table('menu_products')->where('id', $foreign['mpId'])->update(['menu_id' => $otherMenu->id]);

    $body = ($this->asStaff)()
        ->postJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}/skus/bulk", [
            'is_active' => false,
            'menu_product_sku_ids' => [$dish['skus']['Lớn-Không'], $foreign['mpsId']],
            'reason' => 'Hết tô lớn',
        ])
        ->assertOk()
        ->json();

    expect($body['updated'])->toBe(1)
        ->and((bool) DB::table('menu_product_skus')->where('id', $foreign['mpsId'])->value('is_active'))->toBeTrue();
});

it('rejects an empty or oversized id list', function () {
    // `min:1` because "turn off nothing" is never what a cashier meant, and
    // `max:200` so a malformed client cannot walk the whole menu in one call.
    ($this->asStaff)()
        ->postJson("/api/v1/pos/menu-availability/menus/{$this->menu->id}/skus/bulk", [
            'is_active' => false,
            'menu_product_sku_ids' => [],
        ])
        ->assertStatus(422);
});
