<?php

/**
 * plan-056 — the sync-UP door for a LAN availability toggle.
 *
 * The workstation applied the change to its own SQLite while the shop may have
 * been offline, queued it, and replays it here. Three properties matter more
 * than anything else in this file, and each has a named failure:
 *
 *   · IDEMPOTENT. `sync_queue` delivery is at-least-once. A "flip it" op that
 *     lands twice puts a sold-out dish back on sale; a "set false" op survives
 *     any number of replays.
 *   · LENIENT. A rejection here does not fail one request — the op sits at the
 *     HEAD of the queue and blocks every op behind it until a human notices.
 *     That is why an unrecognised staff id is DROPPED, not 422'd.
 *   · The token proves the TERMINAL, never the person. `acted_by_user_id` is a
 *     claim from the body and is vetted before it lands on an audit row.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
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
    ]);
    $this->productType = ProductType::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->menu = Menu::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'Active',
        'is_master' => false,
    ]);

    $this->seedDish = function (): array {
        $product = Product::factory()->create([
            'organization_id' => $this->orgId,
            'brand_id' => $this->brand->id,
            'product_type_id' => $this->productType->id,
            'status' => 'active',
            'is_hidden' => false,
        ]);
        $sku = ProductSku::factory()->create([
            'product_id' => $product->id, 'is_active' => true, 'selling_price' => 1000,
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
            'selling_price' => 1000,
            'is_active' => true,
        ]);

        return ['mpId' => $mpId, 'mpsId' => $mpsId];
    };

    $this->asDevice = fn () => $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"]);
});

it('applies a queued dish toggle with the operator timestamp, not the arrival time', function () {
    // BR-MAE03. An offline shop syncs hours later; recording `now()` would pile
    // an entire disconnected shift onto the single minute the link came back,
    // and every "when did we run out" report would be wrong by that much.
    $dish = ($this->seedDish)();
    $tappedAt = now()->subHours(3);

    ($this->asDevice)()
        ->postJson("/api/v1/workstation/menu-products/{$dish['mpId']}/availability", [
            'is_active' => false,
            'reason' => 'Hết hàng',
            'actor_name' => 'Ann',
            'occurred_at' => $tappedAt->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect(MenuProduct::find($dish['mpId'])->is_active)->toBeFalse();

    $event = MenuAvailabilityEvent::where('entity_id', $dish['mpId'])->sole();
    expect($event->source->value)->toBe('workstation')
        ->and($event->actor_name)->toBe('Ann')
        ->and($event->occurred_at->diffInMinutes($tappedAt))->toBeLessThan(2);
});

it('is idempotent under replay — three deliveries, one event, same state', function () {
    $dish = ($this->seedDish)();
    $payload = ['is_active' => false, 'reason' => 'Hết hàng'];

    foreach ([1, 2, 3] as $_) {
        ($this->asDevice)()
            ->postJson("/api/v1/workstation/menu-products/{$dish['mpId']}/availability", $payload)
            ->assertOk();
    }

    expect(MenuProduct::find($dish['mpId'])->is_active)->toBeFalse()
        ->and(MenuAvailabilityEvent::where('entity_id', $dish['mpId'])->count())->toBe(1);
});

it('applies a queued VARIANT toggle', function () {
    $dish = ($this->seedDish)();

    ($this->asDevice)()
        ->postJson("/api/v1/workstation/menu-product-skus/{$dish['mpsId']}/availability", [
            'is_active' => false, 'reason' => 'Hết size L',
        ])
        ->assertOk();

    expect(MenuProductSku::find($dish['mpsId'])->is_active)->toBeFalse()
        ->and(MenuProduct::find($dish['mpId'])->is_active)->toBeTrue();
});

it('applies a bulk toggle from an explicit id list', function () {
    // The workstation expands "whole section" into the ids that were ON SCREEN
    // and sends those. Replaying a section NAME instead could reach dishes HQ
    // added to the section while the shop was disconnected — dishes the
    // operator never saw and never meant to touch.
    $a = ($this->seedDish)();
    $b = ($this->seedDish)();
    $untouched = ($this->seedDish)();

    ($this->asDevice)()
        ->postJson('/api/v1/workstation/menu-availability/bulk', [
            'menu_id' => $this->menu->id,
            'menu_product_ids' => [$a['mpId'], $b['mpId']],
            'is_active' => false,
            'reason' => 'Hết nguyên liệu',
        ])
        ->assertOk()
        ->assertJsonPath('updated', 2);

    expect(MenuProduct::find($a['mpId'])->is_active)->toBeFalse()
        ->and(MenuProduct::find($b['mpId'])->is_active)->toBeFalse()
        ->and(MenuProduct::find($untouched['mpId'])->is_active)->toBeTrue();
});

it('drops ids that left the menu instead of failing the whole batch', function () {
    // Head-of-line blocking is the failure mode. One dish HQ removed while the
    // shop was offline must not strand the other thirty-nine behind a 404.
    $live = ($this->seedDish)();

    ($this->asDevice)()
        ->postJson('/api/v1/workstation/menu-availability/bulk', [
            'menu_id' => $this->menu->id,
            'menu_product_ids' => [$live['mpId'], (string) Str::uuid()],
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('updated', 1);

    expect(MenuProduct::find($live['mpId'])->is_active)->toBeFalse();
});

it('stores a vetted staff id and DROPS an unvetted one without failing', function () {
    $dish = ($this->seedDish)();

    $insider = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($insider, $this->orgId);

    $outsiderOrg = (string) Str::uuid();
    Organization::factory()->create(['id' => $outsiderOrg, 'console_organization_id' => $outsiderOrg]);
    $outsider = User::factory()->create(['console_organization_id' => $outsiderOrg]);
    grantOrgAccess($outsider, $outsiderOrg);

    ($this->asDevice)()
        ->postJson("/api/v1/workstation/menu-products/{$dish['mpId']}/availability", [
            'is_active' => false,
            'acted_by_user_id' => (string) $insider->getKey(),
            'actor_name' => 'Insider',
        ])
        ->assertOk();

    expect((string) MenuAvailabilityEvent::where('entity_id', $dish['mpId'])->sole()->acted_by_user_id)
        ->toBe((string) $insider->getKey());

    // A stranger's id must never land on an audit row as if Cloud verified it —
    // but the toggle still applies, and `actor_name` still records what the
    // terminal reported. Rejecting would block the queue over metadata.
    $second = ($this->seedDish)();
    ($this->asDevice)()
        ->postJson("/api/v1/workstation/menu-products/{$second['mpId']}/availability", [
            'is_active' => false,
            'acted_by_user_id' => (string) $outsider->getKey(),
            'actor_name' => 'Stranger',
        ])
        ->assertOk();

    $event = MenuAvailabilityEvent::where('entity_id', $second['mpId'])->sole();
    expect($event->acted_by_user_id)->toBeNull()
        ->and($event->actor_name)->toBe('Stranger')
        ->and(MenuProduct::find($second['mpId'])->is_active)->toBeFalse();
});

it('clamps an occurred_at from a broken device clock instead of rejecting it', function () {
    // A terminal whose clock is a year fast would otherwise sort above every
    // real event forever. Clamp, do not reject: a wrong clock must not strand a
    // real "we are out of this dish" in the queue.
    $dish = ($this->seedDish)();

    ($this->asDevice)()
        ->postJson("/api/v1/workstation/menu-products/{$dish['mpId']}/availability", [
            'is_active' => false,
            'occurred_at' => now()->addYear()->toIso8601String(),
        ])
        ->assertOk();

    expect(MenuAvailabilityEvent::where('entity_id', $dish['mpId'])->sole()->occurred_at)
        ->toBeLessThanOrEqual(now()->addMinute());
});

it('404s a dish from another branch', function () {
    $otherOrg = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrg, 'console_organization_id' => $otherOrg]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrg]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrg,
        'console_brand_id' => $otherBrand->console_brand_id,
    ]);
    $otherMenu = Menu::factory()->create([
        'organization_id' => $otherOrg,
        'brand_id' => $otherBrand->id,
        'branch_id' => $otherBranch->id,
        'status' => 'Active',
    ]);
    $foreignProduct = Product::factory()->create([
        'organization_id' => $otherOrg,
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

    ($this->asDevice)()
        ->postJson("/api/v1/workstation/menu-products/{$foreignMpId}/availability", ['is_active' => false])
        ->assertNotFound();

    expect(MenuProduct::find($foreignMpId)->is_active)->toBeTrue();
});

it('401s without a device token', function () {
    $dish = ($this->seedDish)();

    $this->postJson("/api/v1/workstation/menu-products/{$dish['mpId']}/availability", ['is_active' => false])
        ->assertUnauthorized();
});
