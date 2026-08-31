<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProductSku;
use App\Models\MenuSection;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\User;
use App\Services\Product\MenuService;
use App\Services\Product\ProductSkuService;

/**
 * HQ → shop menu sync behaviour (user-reported bugs):
 *   1. HQ layout edits must NOT create duplicate rows at the shop — the shop
 *      row is RELINKED to the recreated master row.
 *   2. Item order (display_order) and section placement follow the master.
 *   3. A branch menu is a faithful MIRROR of its master: new HQ products sync
 *      down ACTIVE; products HQ dropped are REMOVED (soft-deleted), not left
 *      lingering. An existing row's shop toggle survives subsequent syncs.
 *   4. Bulk toggle: bật/tắt tất cả món của một section trong 1 call.
 */
beforeEach(function () {
    $this->orgId = '00000000-0000-0000-0000-000000000001';
    $this->user = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    grantOrgAccess($this->user, $this->orgId);

    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'sync-shop',
        'is_active' => true,
    ]);

    $type = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    foreach (['A', 'B', 'C'] as $key) {
        $this->{"product{$key}"} = Product::factory()->create([
            'organization_id' => $this->orgId,
            'product_type_id' => $type->id,
            'brand_id' => $this->brand->id,
        ]);
        ProductSku::factory()->create([
            'product_id' => $this->{"product{$key}"}->id,
            'selling_price' => 10000,
            'is_active' => true,
        ]);
    }

    $this->masterMenu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'is_master' => true,
        'master_menu_id' => null,
        'status' => 'Approved',
        'created_by_id' => $this->user->id,
    ]);

    $this->hqUrl = "/api/v1/hq/{$this->brand->slug}";
    $this->shopUrl = "/api/v1/shops/{$this->branch->slug}";

    // Master layout: Đồ uống [A, B]; clone to branch.
    $this->actingAs($this->user)
        ->putJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id, $this->productB->id]],
            ],
        ])->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $this->branch->id,
        ])->assertCreated();

    $this->branchMenu = Menu::where('master_menu_id', $this->masterMenu->id)->firstOrFail();
});

/** Helper: shop bật một menu product. */
function activateBranchProduct($mp): void
{
    $mp->update(['is_active' => true]);
}

// =========================================================================
//  Bug 1 + 3 — HQ moves a product to another section, shop syncs
// =========================================================================

it('relinks instead of duplicating when HQ moves a product to another section, keeping shop toggles', function () {
    // Shop turned both items ON.
    $this->branchMenu->menuProducts()->get()->each(fn ($mp) => activateBranchProduct($mp));

    // HQ moves productB into a NEW section "Best Seller" (master row recreated with a new id).
    $this->actingAs($this->user)
        ->putJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
                ['section_name' => 'Best Seller', 'product_ids' => [$this->productB->id]],
            ],
        ])->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk();

    // NO duplicate: still exactly one row per product, none trashed extra.
    expect($this->branchMenu->menuProducts()->count())->toBe(2)
        ->and($this->branchMenu->menuProducts()->where('product_id', $this->productB->id)->count())->toBe(1);

    // Bug 3: the shop's toggles survived the sync.
    $this->branchMenu->menuProducts()->get()->each(
        fn ($mp) => expect($mp->is_active)->toBeTrue()
    );

    // The moved row now sits in the master's new section.
    $masterB = $this->masterMenu->menuProducts()->where('product_id', $this->productB->id)->firstOrFail();
    $branchB = $this->branchMenu->menuProducts()->where('product_id', $this->productB->id)->firstOrFail();
    expect($branchB->menu_section_id)->toBe($masterB->menu_section_id)
        ->and($branchB->master_menu_product_id)->toBe($masterB->id);

    // And check-sync reports nothing new (relinkable ≠ new).
    $this->actingAs($this->user)
        ->getJson("{$this->hqUrl}/menus/{$this->branchMenu->id}/check-sync")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// =========================================================================
//  Bug 2 — item order follows the master after sync
// =========================================================================

it('mirrors master item order and section order on sync', function () {
    // HQ reorders: B before A.
    $this->actingAs($this->user)
        ->putJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productB->id, $this->productA->id]],
            ],
        ])->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk();

    $ordered = $this->branchMenu->menuProducts()->orderBy('display_order')->pluck('product_id')->all();
    expect($ordered)->toBe([$this->productB->id, $this->productA->id]);
});

it('places newly synced products at the master position, ACTIVE (mirrors HQ)', function () {
    // HQ inserts C between B and A.
    $this->actingAs($this->user)
        ->putJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productB->id, $this->productC->id, $this->productA->id]],
            ],
        ])->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk();

    $ordered = $this->branchMenu->menuProducts()->orderBy('display_order')->pluck('product_id')->all();
    expect($ordered)->toBe([$this->productB->id, $this->productC->id, $this->productA->id]);

    // "HQ thêm ≠ shop bán ngay": a product that arrives via SYNC lands
    // INACTIVE (its SKUs too), at the right slot, and the shop enables it when
    // ready. Its SKU rows are still created so the variant list isn't empty.
    $branchC = $this->branchMenu->menuProducts()->where('product_id', $this->productC->id)->firstOrFail();
    expect($branchC->is_active)->toBeFalse()
        ->and($branchC->menuProductSkus()->count())->toBe(1)
        ->and($branchC->menuProductSkus()->firstOrFail()->is_active)->toBeFalse();
});

// =========================================================================
//  Orphans — product truly removed from master
// =========================================================================

it('removes branch rows whose product really left the master menu', function () {
    $this->branchMenu->menuProducts()->get()->each(fn ($mp) => activateBranchProduct($mp));

    // HQ removes productB entirely.
    $this->actingAs($this->user)
        ->putJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id]],
            ],
        ])->assertSuccessful();

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk();

    // B is gone from the branch (soft-deleted) — not left as an inactive
    // "dư món" row. A keeps serving. Branch now mirrors the master exactly.
    $branchB = $this->branchMenu->menuProducts()->withTrashed()->where('product_id', $this->productB->id)->firstOrFail();
    $branchA = $this->branchMenu->menuProducts()->where('product_id', $this->productA->id)->firstOrFail();
    expect($branchB->trashed())->toBeTrue()
        ->and($branchA->is_active)->toBeTrue()
        ->and($this->branchMenu->menuProducts()->count())->toBe(1);
});

// =========================================================================
//  Repeated syncs are idempotent
// =========================================================================

it('is idempotent — re-syncing changes nothing', function () {
    $this->branchMenu->menuProducts()->get()->each(fn ($mp) => activateBranchProduct($mp));

    $this->actingAs($this->user)->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")->assertOk();
    $snapshot = $this->branchMenu->menuProducts()
        ->orderBy('display_order')
        ->get(['product_id', 'menu_section_id', 'display_order', 'is_active'])
        ->toArray();

    $this->actingAs($this->user)->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")->assertOk();
    $after = $this->branchMenu->menuProducts()
        ->orderBy('display_order')
        ->get(['product_id', 'menu_section_id', 'display_order', 'is_active'])
        ->toArray();

    expect($after)->toBe($snapshot)
        ->and($this->branchMenu->menuProducts()->count())->toBe(2);
});

// =========================================================================
//  Feature 4 — bulk toggle a whole section
// =========================================================================

it('bulk-enables and bulk-disables every product of one section', function () {
    $section = $this->branchMenu->menuSections()->firstOrFail();

    // Start from everything OFF (mimics the post-sync "shop must opt in" state).
    $this->branchMenu->menuProducts()->update(['is_active' => false]);
    expect($this->branchMenu->menuProducts()->where('is_active', true)->count())->toBe(0);

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sections/{$section->id}/products/bulk-toggle", [
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('updated', 2);

    expect($this->branchMenu->menuProducts()->where('is_active', true)->count())->toBe(2);

    // Re-running is a no-op (0 rows flipped).
    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sections/{$section->id}/products/bulk-toggle", [
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('updated', 0);

    // And back off.
    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sections/{$section->id}/products/bulk-toggle", [
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('updated', 2);

    expect($this->branchMenu->menuProducts()->where('is_active', true)->count())->toBe(0);
});

it('404s bulk toggle for a section not attached to this menu', function () {
    $foreignSection = MenuSection::create([
        'name' => 'Foreign',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sections/{$foreignSection->id}/products/bulk-toggle", [
            'is_active' => true,
        ])
        ->assertNotFound();
});

// =========================================================================
//  Schedules — sync mirrors master windows (bug: giờ HQ đổi mà shop giữ 3 lịch)
// =========================================================================

it('mirrors master schedule edits instead of piling up stale windows', function () {
    // HQ defines one window 10:00–18:00 on the master.
    $masterSchedule = $this->masterMenu->schedules()->create([
        'start_time' => '10:00:00',
        'end_time' => '18:00:00',
        'days_of_week' => '[1,2,3,4,5]',
        'is_active' => true,
        'priority' => 1,
        'created_by_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")->assertOk();
    expect($this->branchMenu->schedules()->count())->toBe(1);

    // HQ edits the window to 10:00–16:00 (same row, new times).
    $masterSchedule->update(['end_time' => '16:00:00']);

    $this->actingAs($this->user)->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")->assertOk();

    // Shop shows exactly ONE window with the NEW time — no stale 18:00 row.
    $branchSchedules = $this->branchMenu->schedules()->get();
    expect($branchSchedules)->toHaveCount(1)
        ->and($branchSchedules->first()->getRawOriginal('end_time'))->toBe('16:00:00')
        ->and($branchSchedules->first()->master_schedule_id)->toBe($masterSchedule->id);

    // Re-sync stays idempotent.
    $this->actingAs($this->user)->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")->assertOk();
    expect($this->branchMenu->schedules()->count())->toBe(1);
});

it('adopts legacy unlinked branch schedules and removes stale duplicates', function () {
    // Simulate the pre-fix state: master has ONE window, branch accumulated
    // THREE rows (one matching, two stale) with no origin links.
    $masterSchedule = $this->masterMenu->schedules()->create([
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'days_of_week' => '[1,2,3]',
        'is_active' => true,
        'priority' => 1,
        'created_by_id' => $this->user->id,
    ]);

    foreach ([['10:00:00', '18:00:00'], ['10:00:00', '16:00:00'], ['10:00:00', '16:00:00']] as [$start, $end]) {
        $this->branchMenu->schedules()->create([
            'start_time' => $start,
            'end_time' => $end,
            'days_of_week' => '[1,2,3]',
            'is_active' => true,
            'priority' => 1,
            'created_by_id' => $this->user->id,
        ]);
    }
    expect($this->branchMenu->schedules()->count())->toBe(3);

    $this->actingAs($this->user)->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")->assertOk();

    // Exactly one window remains — content-matched row adopted, the stale
    // 18:00 row and the duplicate removed.
    $branchSchedules = $this->branchMenu->schedules()->get();
    expect($branchSchedules)->toHaveCount(1)
        ->and($branchSchedules->first()->getRawOriginal('end_time'))->toBe('16:00:00')
        ->and($branchSchedules->first()->master_schedule_id)->toBe($masterSchedule->id);
});

it('stamps master_schedule_id when cloning a master menu with schedules', function () {
    $masterSchedule = $this->masterMenu->schedules()->create([
        'start_time' => '09:00:00',
        'end_time' => '11:00:00',
        'days_of_week' => '[6,0]',
        'is_active' => true,
        'priority' => 2,
        'created_by_id' => $this->user->id,
    ]);

    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/clone-to-branch", [
            'branch_id' => $otherShop->id,
        ])->assertCreated();

    $clone = Menu::where('master_menu_id', $this->masterMenu->id)
        ->where('branch_id', $otherShop->id)
        ->firstOrFail();

    expect($clone->schedules()->count())->toBe(1)
        ->and($clone->schedules()->first()->master_schedule_id)->toBe($masterSchedule->id);
});

// =========================================================================
//  Deleted products — sync + serialization must survive them
// =========================================================================

it('skips master rows whose product was soft-deleted and removes their branch rows', function () {
    $this->branchMenu->menuProducts()->get()->each(fn ($mp) => activateBranchProduct($mp));

    // HQ soft-deletes productB entirely (e.g. catalog prune) — its master
    // menu row still exists but references a trashed product.
    $this->productB->delete();

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk();

    $branchA = $this->branchMenu->menuProducts()->where('product_id', $this->productA->id)->firstOrFail();
    $branchB = $this->branchMenu->menuProducts()->withTrashed()->where('product_id', $this->productB->id)->firstOrFail();

    // A keeps serving; B (product gone from the catalog) is removed from the
    // branch, and the response serialized without a 500 despite the null
    // product relation.
    expect($branchA->is_active)->toBeTrue()
        ->and($branchB->trashed())->toBeTrue();

    // check-sync also survives.
    $this->actingAs($this->user)
        ->getJson("{$this->hqUrl}/menus/{$this->branchMenu->id}/check-sync")
        ->assertOk();
});

// =========================================================================
//  SKU restore-or-create — the (menu_product_id, product_sku_id) unique
//  index excludes deleted_at, so a soft-deleted SKU still holds the slot.
//  Re-syncing a row whose SKUs were removed must restore, not collide.
// =========================================================================

it('restores soft-deleted branch SKUs on sync instead of hitting the unique index', function () {
    $branchA = $this->branchMenu->menuProducts()->where('product_id', $this->productA->id)->firstOrFail();

    // Soft-delete the branch row's SKU: the unique slot stays occupied while
    // the live count drops to 0 — exactly the state that made sync throw 23000.
    $sku = $branchA->menuProductSkus()->firstOrFail();
    $sku->delete();
    expect($branchA->menuProductSkus()->count())->toBe(0);

    // Sync used to blow up with a duplicate-entry SQLSTATE here.
    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk();

    // The SKU is restored (not duplicated). On SYNC it comes back INACTIVE —
    // "HQ thêm ≠ shop bán ngay" — so the shop re-enables it deliberately.
    expect($branchA->menuProductSkus()->count())->toBe(1)
        ->and($branchA->menuProductSkus()->withTrashed()->count())->toBe(1)
        ->and($branchA->menuProductSkus()->firstOrFail()->is_active)->toBeFalse();
});

// =========================================================================
//  Unlinked orphans — a cloned menu must mirror its master. Rows with a
//  NULL master_menu_product_id whose product is not in the master (shops
//  cannot add products to a clone) get removed, not left showing.
// =========================================================================

it('removes unlinked branch rows whose product is not in the master', function () {
    $this->branchMenu->menuProducts()->get()->each(fn ($mp) => activateBranchProduct($mp));

    // Inject a NULL-master active row for productC (never in the master).
    $section = $this->branchMenu->menuSections()->firstOrFail();
    $orphan = $this->branchMenu->menuProducts()->create([
        'product_id' => $this->productC->id,
        'menu_section_id' => $section->id,
        'is_active' => true,
        'display_order' => 99,
        'master_menu_product_id' => null,
    ]);
    $orphan->menuProductSkus()->create([
        'product_sku_id' => $this->productC->skus()->firstOrFail()->id,
        'selling_price' => 10000,
        'is_price_overridden' => false,
        'is_active' => true,
    ]);

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk();

    // The unlinked row is gone (soft-deleted), SKUs too — nothing extra shows.
    $orphan = $this->branchMenu->menuProducts()->withTrashed()->find($orphan->id);
    expect($orphan->trashed())->toBeTrue()
        ->and($orphan->menuProductSkus()->count())->toBe(0);

    // Legit master-linked rows are untouched.
    $branchA = $this->branchMenu->menuProducts()->where('product_id', $this->productA->id)->firstOrFail();
    expect($branchA->is_active)->toBeTrue();
});

// =========================================================================
//  Master menu removed at HQ — branch schedule mirrors must not linger.
// =========================================================================

// =========================================================================
//  End-to-end schedule sync through the real HTTP layer (HQ API → shop sync
//  → shop effective-schedule read). Uses integer days_of_week bitmasks the
//  same way the admin-web UI does, unlike the service-level tests above which
//  poke JSON-ish strings straight into the column.
// =========================================================================

it('propagates HQ schedule create/edit/delete down to the shop effective read', function () {
    $scheduleUrl = "{$this->hqUrl}/menus/{$this->masterMenu->id}/schedules";
    $shopScheduleUrl = "{$this->shopUrl}/menus/{$this->branchMenu->id}/schedules";

    // --- HQ creates a Mon–Fri lunch window (bitmask Mon..Fri = 2+4+8+16+32 = 62).
    $created = $this->actingAs($this->user)->postJson($scheduleUrl, [
        'start_time' => '10:00',
        'end_time' => '14:00',
        'days_of_week' => 62,
        'is_active' => true,
        'priority' => 1,
    ])->assertCreated()->json('data.id');

    // Shop syncs, then reads its effective schedules over HTTP.
    $this->actingAs($this->user)->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")->assertOk();

    $rows = $this->actingAs($this->user)->getJson($shopScheduleUrl)->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['hq_defaults']['start_time'])->toBe('10:00:00')
        ->and($rows[0]['hq_defaults']['end_time'])->toBe('14:00:00')
        ->and($rows[0]['days_of_week'])->toBe(62);

    // --- HQ edits the window: new end time + drops Fri (bitmask Mon..Thu = 30).
    $this->actingAs($this->user)
        ->putJson("{$scheduleUrl}/{$created}", ['end_time' => '15:00', 'days_of_week' => 30])
        ->assertOk();

    $this->actingAs($this->user)->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")->assertOk();

    $rows = $this->actingAs($this->user)->getJson($shopScheduleUrl)->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['hq_defaults']['end_time'])->toBe('15:00:00')
        ->and($rows[0]['days_of_week'])->toBe(30);

    // --- HQ deletes the window entirely.
    $this->actingAs($this->user)->deleteJson("{$scheduleUrl}/{$created}")->assertSuccessful();

    $this->actingAs($this->user)->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")->assertOk();

    $rows = $this->actingAs($this->user)->getJson($shopScheduleUrl)->assertOk()->json('data');
    expect($rows)->toHaveCount(0);
});

it('mirrors the master service_type onto the branch on sync', function () {
    // Branch explicitly overrides to Both (shadows the effective inheritance).
    $this->branchMenu->update(['service_type' => 'Both']);
    // HQ switches the master to DineIn only.
    $this->masterMenu->update(['service_type' => 'DineIn']);

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk()
        ->assertJsonPath('data.hq_service_type', 'DineIn')
        ->assertJsonPath('data.shop_service_type', 'DineIn')
        ->assertJsonPath('data.effective_service_type', 'DineIn');

    expect($this->branchMenu->fresh()->service_type)->toBe('DineIn');
});

it('mirrors a NULL master service_type (inherit) onto the branch on sync', function () {
    $this->branchMenu->update(['service_type' => 'Takeaway']);
    $this->masterMenu->update(['service_type' => null]);

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk()
        ->assertJsonPath('data.hq_service_type', null)
        ->assertJsonPath('data.shop_service_type', null)
        ->assertJsonPath('data.effective_service_type', 'Both');

    expect($this->branchMenu->fresh()->service_type)->toBeNull();
});

it('drops branch schedule mirrors when the master menu is soft-deleted', function () {
    // Branch carries a mirrored window.
    $this->branchMenu->schedules()->create([
        'start_time' => '10:00:00',
        'end_time' => '16:00:00',
        'days_of_week' => '[1,2,3]',
        'is_active' => true,
        'priority' => 1,
        'created_by_id' => $this->user->id,
    ]);
    expect($this->branchMenu->schedules()->count())->toBe(1);

    // HQ removes the whole master menu.
    $this->masterMenu->delete();

    app(MenuService::class)->syncFromMaster($this->branchMenu->fresh());

    // No master windows left to mirror → branch schedules are cleared.
    expect($this->branchMenu->schedules()->count())->toBe(0);
});

// =========================================================================
//  HQ "variant off" mirrors to branch menu SKUs ON SYNC (not immediately) —
//  root cause of the inactive-variant surfacing on shop/customer menus.
//  Disabling a variant at HQ leaves the shop untouched until it runs
//  "Đồng bộ từ HQ", consistent with every other HQ template edit.
// =========================================================================

it('does NOT touch branch menu SKUs when HQ merely disables the ProductSku (no sync yet)', function () {
    $sku = $this->productA->skus()->first();
    $branchMps = MenuProductSku::where('product_sku_id', $sku->id)
        ->whereHas('menuProduct.menu', fn ($q) => $q->where('is_master', false))
        ->get();
    expect($branchMps)->not->toBeEmpty()
        ->and($branchMps->every(fn ($m) => $m->is_active))->toBeTrue();

    // HQ disables the variant — but the shop has not synced.
    app(ProductSkuService::class)->toggleStatus($sku);

    // Shop stays exactly as it was until it explicitly syncs.
    $branchMps->each(fn ($m) => expect($m->fresh()->is_active)->toBeTrue());
});

it('deactivates branch menu SKUs pointing at a disabled ProductSku on syncFromMaster', function () {
    $sku = $this->productA->skus()->first();
    $branchMps = MenuProductSku::where('product_sku_id', $sku->id)
        ->whereHas('menuProduct.menu', fn ($q) => $q->where('is_master', false))
        ->get();
    expect($branchMps->every(fn ($m) => $m->is_active))->toBeTrue();

    // HQ disables the variant, then the shop runs "Đồng bộ từ HQ".
    app(ProductSkuService::class)->toggleStatus($sku);
    app(MenuService::class)->syncFromMaster($this->branchMenu->fresh());

    $branchMps->each(fn ($m) => expect($m->fresh()->is_active)->toBeFalse());
});

it('keeps the shop price override on the SKU it deactivates during sync', function () {
    $sku = $this->productA->skus()->first();
    $branchMps = MenuProductSku::where('product_sku_id', $sku->id)
        ->whereHas('menuProduct.menu', fn ($q) => $q->where('is_master', false))
        ->get();
    // Shop set its own price on every branch line for this variant.
    $branchMps->each(fn ($m) => $m->update(['selling_price' => 7777, 'is_price_overridden' => true]));

    app(ProductSkuService::class)->toggleStatus($sku); // HQ disables
    app(MenuService::class)->syncFromMaster($this->branchMenu->fresh());

    // Deactivated, but the override price is untouched — sync never resets it.
    $branchMps->each(function ($m) {
        $fresh = $m->fresh();
        expect($fresh->is_active)->toBeFalse()
            ->and((float) $fresh->selling_price)->toBe(7777.0)
            ->and($fresh->is_price_overridden)->toBeTrue();
    });
});

// =========================================================================
//  "HQ thêm ≠ shop bán ngay": products/SKUs that arrive via SYNC land
//  INACTIVE (the shop enables them), while the FIRST clone stays active so a
//  freshly-cloned menu is ready to sell.
// =========================================================================

it('lands a product added at HQ after clone as INACTIVE on the shop after sync', function () {
    // Clone already happened in beforeEach: products A + B are active on the
    // shop (clone = ready to sell). HQ now adds product C.
    $this->actingAs($this->user)
        ->putJson("{$this->hqUrl}/menus/{$this->masterMenu->id}/layout", [
            'menu_items' => [
                ['section_name' => 'Đồ uống', 'product_ids' => [$this->productA->id, $this->productB->id, $this->productC->id]],
            ],
        ])->assertSuccessful();

    // Existing cloned products are still active (clone contract) BEFORE sync.
    $this->branchMenu->menuProducts()->get()->each(fn ($mp) => expect($mp->is_active)->toBeTrue());

    $this->actingAs($this->user)
        ->postJson("{$this->shopUrl}/menus/{$this->branchMenu->id}/sync")
        ->assertOk();

    // The NEW product (C) arrived via sync → inactive, with its SKUs inactive
    // too. The pre-existing clones keep their active state.
    $branchC = $this->branchMenu->menuProducts()->where('product_id', $this->productC->id)->firstOrFail();
    expect($branchC->is_active)->toBeFalse()
        ->and($branchC->menuProductSkus()->firstOrFail()->is_active)->toBeFalse();

    $branchA = $this->branchMenu->menuProducts()->where('product_id', $this->productA->id)->firstOrFail();
    expect($branchA->is_active)->toBeTrue();
});
