<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\FloatingSection;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'fs-shop-test',
        'is_active' => true,
    ]);

    $this->adminRole = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        ['name' => 'Org Admin', 'level' => 100],
    );
    $this->adminRole->permissions()->syncWithoutDetaching(collect(['menu.view', 'menu.manage'])
        ->map(fn ($slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => $slug, 'group' => 'menu'])->id));
    $this->shopManagerRole = Role::firstOrCreate(
        ['slug' => 'shop-manager'],
        ['name' => 'Shop Manager', 'level' => 40],
    );
    $this->shopStaffRole = Role::firstOrCreate(
        ['slug' => 'shop-staff'],
        ['name' => 'Shop Staff', 'level' => 5],
    );

    $this->admin = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->admin->assignRole($this->adminRole, $this->orgId);

    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->baseUrl = "/api/v1/hq/{$this->brand->slug}";
    $this->shopBase = "/api/v1/shops/{$this->shop->slug}";

    $this->master = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'name' => 'Happy Hour',
    ]);

    $this->product = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products", [
            'product_ids' => [$this->product->id],
        ]);

    // Clone AFTER the master has its product, so the branch copy carries it.
    // cloneToBranch() is a one-time snapshot — the shop must call POST
    // .../sync afterwards to pull any further HQ changes.
    $this->clone = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $this->shop->id,
        ])
        ->json('data');

    $this->shopStaff = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->shopStaff->assignRole($this->shopStaffRole, $this->orgId, $this->shop->id);

    $this->shopManager = User::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);
    $this->shopManager->assignRole($this->shopManagerRole, $this->orgId, $this->shop->id);
});

it('lists only the shop own branch clone, not the HQ master', function () {
    $response = $this->actingAs($this->shopStaff)->getJson("{$this->shopBase}/floating-sections");

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.id'))->toBe($this->clone['id']);
    expect($response->json('data.0.branch_id'))->toBe($this->shop->id);
});

it('allows shop staff to view and toggle a product', function () {
    $cloneProduct = FloatingSection::find($this->clone['id'])->products()->firstOrFail();

    $this->actingAs($this->shopStaff)
        ->getJson("{$this->shopBase}/floating-sections/{$this->clone['id']}")
        ->assertOk();

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/products/{$cloneProduct->id}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('forbids shop staff from overriding a SKU price, but allows shop manager', function () {
    ProductSku::factory()->create(['product_id' => $this->product->id, 'is_active' => true]);
    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();

    $cloneProduct = FloatingSection::find($this->clone['id'])->products()->firstOrFail();
    $cloneSku = $cloneProduct->skus()->firstOrFail();

    $this->actingAs($this->shopStaff)
        ->patchJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/products/{$cloneProduct->id}/skus/{$cloneSku->id}/price", [
            'selling_price' => 999,
        ])
        ->assertForbidden();

    $this->actingAs($this->shopManager)
        ->patchJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/products/{$cloneProduct->id}/skus/{$cloneSku->id}/price", [
            'selling_price' => 999,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_price_overridden', true);
});

it('lets shop staff view and toggle the schedule of its own clone, but not create/edit/delete it', function () {
    $schedule = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->clone['id']}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
        ])
        ->json('data');

    $this->actingAs($this->shopStaff)
        ->getJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules")
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules/{$schedule['id']}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules/{$schedule['id']}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', true);

    // No create/update/delete routes exist for the shop — they stay HQ-only.
    // POST to the schedules index URI matches no method there (only GET is
    // registered), so Laravel reports 405 rather than 404.
    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules", [
            'start_time' => '20:00', 'end_time' => '21:00', 'days_of_week' => 127,
        ])
        ->assertStatus(405);

    $this->actingAs($this->shopStaff)
        ->putJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules/{$schedule['id']}", [
            'start_time' => '10:00',
        ])
        ->assertNotFound();

    $this->actingAs($this->shopStaff)
        ->deleteJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules/{$schedule['id']}")
        ->assertNotFound();

    // Toggling the clone's schedule never touches the HQ master's own schedules.
    expect($this->master->schedules()->count())->toBe(0);
});

it('forbids a shop staff from reaching another shop, and 404s the HQ master directly', function () {
    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'fs-other-shop',
        'is_active' => true,
    ]);
    $otherShopBase = "/api/v1/shops/{$otherShop->slug}";

    // The staff is assigned to $this->shop only, so the shop-context
    // middleware rejects the foreign shop before the id is ever resolved.
    $this->actingAs($this->shopStaff)
        ->getJson("{$otherShopBase}/floating-sections/{$this->clone['id']}")
        ->assertForbidden();

    // Reaching through the staff's own shop: the master row has branch_id = null,
    // so it never matches the shop's branch-scoped lookup and 404s.
    $this->actingAs($this->shopStaff)
        ->getJson("{$this->shopBase}/floating-sections/{$this->master->id}")
        ->assertNotFound();
});

it('syncs product set + schedule timing from the master, preserving the shop own toggles', function () {
    // Shop turns its cloned product off — this must survive the sync below.
    $cloneProduct = FloatingSection::find($this->clone['id'])->products()->firstOrFail();
    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/products/{$cloneProduct->id}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    // HQ adds a schedule + a second product to the master AFTER the clone
    // already exists — cloneToBranch never ran again, so the shop's copy
    // doesn't have either yet.
    $masterSchedule = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
        ])
        ->json('data');

    $secondProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->productType->id,
    ]);
    $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products", [
            'product_ids' => [$secondProduct->id],
        ]);

    // HQ also drops the original product from the master.
    $masterProduct = $this->master->products()->where('product_id', $cloneProduct->product_id)->firstOrFail();
    $this->actingAs($this->admin)
        ->deleteJson("{$this->baseUrl}/floating-sections/{$this->master->id}/products/{$masterProduct->id}")
        ->assertNoContent();

    $sync = $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();

    $refreshed = FloatingSection::find($this->clone['id']);

    // Original product was dropped from the master, so it's removed here.
    expect($refreshed->products()->where('product_id', $cloneProduct->product_id)->exists())->toBeFalse();

    // New product arriving via SYNC lands INACTIVE ("HQ thêm ≠ shop bán
    // ngay") — the shop enables it when ready.
    $newBranchProduct = $refreshed->products()->where('product_id', $secondProduct->id)->firstOrFail();
    expect($newBranchProduct->is_active)->toBeFalse();

    // New schedule was pulled in, carrying the master's time window.
    $branchSchedule = $refreshed->schedules()->where('master_schedule_id', $masterSchedule['id'])->firstOrFail();
    expect($branchSchedule->getRawOriginal('start_time'))->toBe('17:00:00');

    $sync->assertJsonPath('data.products_count', 1);
});

it('lets a shop manager toggle a synced schedule off, and the toggle survives a later sync', function () {
    $masterSchedule = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
        ])
        ->json('data');

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();

    $branchSchedule = FloatingSection::find($this->clone['id'])
        ->schedules()->where('master_schedule_id', $masterSchedule['id'])->firstOrFail();

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules/{$branchSchedule->id}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    // HQ nudges the time window — sync again and the shop's off-toggle
    // must still be off, while the time itself follows HQ.
    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/floating-sections/{$this->master->id}/schedules/{$masterSchedule['id']}", [
            'start_time' => '18:00',
        ])
        ->assertOk();

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();

    $branchSchedule->refresh();
    expect($branchSchedule->is_active)->toBeFalse();
    expect($branchSchedule->getRawOriginal('start_time'))->toBe('18:00:00');
});

it('lets shop staff override a synced schedule time, survives a later sync, and can reset back to HQ', function () {
    $masterSchedule = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
        ])
        ->json('data');

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();

    $branchSchedule = FloatingSection::find($this->clone['id'])
        ->schedules()->where('master_schedule_id', $masterSchedule['id'])->firstOrFail();

    // Shop overrides its own displayed time.
    $this->actingAs($this->shopStaff)
        ->putJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules/{$branchSchedule->id}/override", [
            'start_time' => '17:30', 'end_time' => '20:00',
        ])
        ->assertOk()
        ->assertJsonPath('data.is_time_overridden', true);

    $branchSchedule->refresh();
    expect($branchSchedule->getRawOriginal('start_time'))->toBe('17:30:00');

    // HQ changes the master's own time — sync must NOT clobber the override.
    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/floating-sections/{$this->master->id}/schedules/{$masterSchedule['id']}", [
            'start_time' => '16:00',
        ])
        ->assertOk();

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();

    $branchSchedule->refresh();
    expect($branchSchedule->getRawOriginal('start_time'))->toBe('17:30:00');
    expect($branchSchedule->is_time_overridden)->toBeTrue();

    // Reset — pulls the master's CURRENT (post-nudge) time and clears the flag.
    $this->actingAs($this->shopStaff)
        ->deleteJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules/{$branchSchedule->id}/override")
        ->assertOk()
        ->assertJsonPath('data.is_time_overridden', false);

    $branchSchedule->refresh();
    expect($branchSchedule->getRawOriginal('start_time'))->toBe('16:00:00');

    // A further sync now follows HQ's time again (override cleared).
    $this->actingAs($this->admin)
        ->putJson("{$this->baseUrl}/floating-sections/{$this->master->id}/schedules/{$masterSchedule['id']}", [
            'start_time' => '15:00',
        ])
        ->assertOk();
    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();
    $branchSchedule->refresh();
    expect($branchSchedule->getRawOriginal('start_time'))->toBe('15:00:00');
});

it('rejects an override request with no fields, and rejects an invalid time range', function () {
    $masterSchedule = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/schedules", [
            'start_time' => '17:00', 'end_time' => '19:00', 'days_of_week' => 127,
        ])
        ->json('data');
    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();
    $branchSchedule = FloatingSection::find($this->clone['id'])
        ->schedules()->where('master_schedule_id', $masterSchedule['id'])->firstOrFail();

    $this->actingAs($this->shopStaff)
        ->putJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules/{$branchSchedule->id}/override", [])
        ->assertUnprocessable();

    $this->actingAs($this->shopStaff)
        ->putJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/schedules/{$branchSchedule->id}/override", [
            'start_time' => '20:00',
        ])
        ->assertUnprocessable();
});

it('lets shop staff toggle a single SKU within a cloned product, independent of other SKUs', function () {
    $sku1 = ProductSku::factory()->create(['product_id' => $this->product->id, 'is_active' => true]);
    $sku2 = ProductSku::factory()->withSequencedOption()->create(['product_id' => $this->product->id, 'is_active' => true]);

    // Re-sync so the clone (created in beforeEach before these SKUs existed)
    // picks up the newly added variants.
    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();

    $cloneProduct = FloatingSection::find($this->clone['id'])->products()->firstOrFail();
    expect($cloneProduct->skus()->count())->toBe(2);

    $cloneSku1 = $cloneProduct->skus()->where('product_sku_id', $sku1->id)->firstOrFail();
    $cloneSku2 = $cloneProduct->skus()->where('product_sku_id', $sku2->id)->firstOrFail();

    // Both variants arrived via SYNC → inactive; the shop turns them on. Now
    // test that toggling one is independent of the other.
    $cloneSku1->update(['is_active' => true]);
    $cloneSku2->update(['is_active' => true]);

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/products/{$cloneProduct->id}/skus/{$cloneSku1->id}/toggle")
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($cloneSku1->fresh()->is_active)->toBeFalse();
    // Toggling one SKU never touches its sibling.
    expect($cloneSku2->fresh()->is_active)->toBeTrue();

    // Toggling the branch's own SKU row never touches the master's.
    $masterProduct = $this->master->products()->firstOrFail();
    $masterSku1 = $masterProduct->skus()->where('product_sku_id', $sku1->id)->firstOrFail();
    expect($masterSku1->is_active)->toBeTrue();
});

it('404s toggling a SKU that belongs to a different shop clone', function () {
    $sku = ProductSku::factory()->create(['product_id' => $this->product->id, 'is_active' => true]);
    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$this->clone['id']}/sync")
        ->assertOk();

    $cloneProduct = FloatingSection::find($this->clone['id'])->products()->firstOrFail();
    $cloneSku = $cloneProduct->skus()->where('product_sku_id', $sku->id)->firstOrFail();

    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'fs-sku-other-shop',
        'is_active' => true,
    ]);
    $otherClone = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections/{$this->master->id}/clone-to-branch", [
            'branch_id' => $otherShop->id,
        ])
        ->json('data');
    $otherProduct = FloatingSection::find($otherClone['id'])->products()->firstOrFail();

    // Cross the wrong clone's product id with the right SKU id — must 404,
    // not fall through to a different shop's SKU.
    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$otherClone['id']}/products/{$otherProduct->id}/skus/{$cloneSku->id}/toggle")
        ->assertNotFound();
});

it('422s a sync on a floating section that has no master (not a clone)', function () {
    $standalone = $this->actingAs($this->admin)
        ->postJson("{$this->baseUrl}/floating-sections", ['name' => 'Standalone'])
        ->json('data');

    // Simulate a directly-created branch row with no master link.
    FloatingSection::where('id', $standalone['id'])->update(['branch_id' => $this->shop->id]);

    $this->actingAs($this->shopStaff)
        ->postJson("{$this->shopBase}/floating-sections/{$standalone['id']}/sync")
        ->assertStatus(422);
});
