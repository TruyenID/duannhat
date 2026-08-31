<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\PrintTemplate;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\Zone;
use App\Services\Workstation\SyncManifestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * #1175 phase 2 — GET /api/v1/workstation/sync-manifest.
 *
 * The workstation's 5s tick becomes one conditional GET: 304 when nothing
 * changed, otherwise a per-feed opaque version map so it re-pulls only what
 * moved. Feed keys are a FROZEN contract with the Go client.
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
    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->getManifest = fn (array $headers = []) => $this
        ->withHeaders(array_merge(['Authorization' => "Bearer {$this->wsToken}"], $headers))
        ->getJson('/api/v1/workstation/sync-manifest');

    // Build one priced menu line on the branch (mints catalog revision 1 via
    // the sync-queue-inline rebuild job).
    $this->buildPricedLine = function (): MenuProductSku {
        $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $product = Product::factory()->active()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
        ]);
        $sku = ProductSku::factory()->create([
            'product_id' => $product->id, 'selling_price' => 1000, 'is_active' => true,
        ]);
        $menu = Menu::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id, 'status' => 'Active',
        ]);
        $line = MenuProduct::factory()->create([
            'menu_id' => $menu->id, 'product_id' => $product->id, 'is_active' => true,
        ]);

        return MenuProductSku::factory()->create([
            'menu_product_id' => $line->id, 'product_sku_id' => $sku->id,
            'selling_price' => 1000, 'is_price_overridden' => true, 'is_active' => true,
        ]);
    };
});

// =========================================================================
//  Shape — the feed keys are a FROZEN contract
// =========================================================================

it('returns the manifest with exactly the frozen feed keys and an ETag', function () {
    $resp = ($this->getManifest)()->assertOk();

    expect($resp->json('data.manifest_version'))->toBeString()->not->toBe('');

    // FROZEN: the Go client is built against exactly these names, in any
    // order — but we pin order too so the aggregate hash is stable.
    expect(array_keys($resp->json('data.feeds')))->toBe([
        'menu', 'handy_menu', 'menu_catalog', 'menu_schedules', 'promotions',
        'coupons', 'staff', 'branch_settings', 'zones', 'tables', 'lots',
        'print_images',
        // #2712 — appended, never inserted between existing keys.
        'print_templates', 'expected_build', 'payment_methods',
        'peripheral_devices', 'printers', 'till', 'till_denominations',
        'tender_categories', 'tender_types',
    ]);

    foreach ($resp->json('data.feeds') as $version) {
        expect($version)->toBeString()->not->toBe('');
    }

    expect($resp->headers->get('ETag'))->toBe('"'.$resp->json('data.manifest_version').'"');
});

it('requires a device token', function () {
    $this->getJson('/api/v1/workstation/sync-manifest')->assertUnauthorized();
});

// =========================================================================
//  ETag / 304 — the common no-change tick is cheap and empty
// =========================================================================

it('answers 304 with an empty body when If-None-Match matches, and 200 again after a change', function () {
    $etag = ($this->getManifest)()->assertOk()->headers->get('ETag');

    $notModified = ($this->getManifest)(['If-None-Match' => $etag]);
    $notModified->assertStatus(304);
    expect($notModified->getContent())->toBe('')
        ->and($notModified->headers->get('ETag'))->toBe($etag);

    // A weak validator from a tolerant client still hits.
    ($this->getManifest)(['If-None-Match' => 'W/'.$etag])->assertStatus(304);

    // Change a feed's underlying rows, get past the memo window → 200 with
    // a NEW version.
    Zone::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();

    $changed = ($this->getManifest)(['If-None-Match' => $etag])->assertOk();
    expect('"'.$changed->json('data.manifest_version').'"')->not->toBe($etag);
});

// =========================================================================
//  Per-feed versions move with their own rows — and only their own
// =========================================================================

it('moves the zones version when a zone changes, leaving unrelated feeds untouched', function () {
    $before = ($this->getManifest)()->json('data.feeds');

    $zone = Zone::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();

    $after = ($this->getManifest)()->json('data.feeds');
    expect($after['zones'])->not->toBe($before['zones'])
        ->and($after['coupons'])->toBe($before['coupons'])
        ->and($after['staff'])->toBe($before['staff'])
        ->and($after['menu'])->toBe($before['menu']);

    // Deactivating it moves the version again (the row leaves the pull scope).
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();
    $zone->update(['is_active' => false]);
    $final = ($this->getManifest)()->json('data.feeds');
    expect($final['zones'])->not->toBe($after['zones']);
});

it('moves the coupons version on a PIVOT hard-delete (branch whitelist detach)', function () {
    $coupon = Coupon::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'code' => 'PIVOTCASE',
        'status' => 'draft',
    ]);
    $coupon->branches()->attach($this->branch->id);
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();

    $attached = ($this->getManifest)()->json('data.feeds.coupons');

    // Detach touches ONLY the pivot table — coupons.updated_at does not move,
    // but the pull payload shrinks; count(*) over the pivot catches it.
    $coupon->branches()->detach($this->branch->id);
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();

    $detached = ($this->getManifest)()->json('data.feeds.coupons');
    expect($detached)->not->toBe($attached);
});

// =========================================================================
//  Catalog feeds ride the catalog revision (#1095/#1114)
// =========================================================================

it('moves the three catalog feeds together as the revision bumps', function () {
    // The version string is OPAQUE by contract (SyncManifestService docblock):
    // equality is the only defined operation, so this asserts MOVEMENT and
    // AGREEMENT, never a literal. It used to pin 'rev-0'/'rev-1'/'rev-2', which
    // is why plan-056 — appending an availability digest, a change no client
    // can observe — turned it red for no behavioural reason.
    $rev0 = ($this->getManifest)()->json('data.feeds.menu');
    expect($rev0)->toStartWith('rev-0');

    // Minting revision 1 goes through RebuildCatalogRevisionJob, which drops
    // the manifest memo — no TTL wait needed for catalog changes.
    $menuSku = ($this->buildPricedLine)();

    $feeds = ($this->getManifest)()->json('data.feeds');
    expect($feeds['menu'])->not->toBe($rev0)
        // All three ride one source, so they must never disagree — a client
        // that re-pulled only `menu` on a `menu_catalog` change is the bug this
        // line exists to catch.
        ->and($feeds['handy_menu'])->toBe($feeds['menu'])
        ->and($feeds['menu_catalog'])->toBe($feeds['menu']);

    $rev1 = $feeds['menu'];
    $menuSku->update(['selling_price' => 1200]);
    expect(($this->getManifest)()->json('data.feeds.menu'))->not->toBe($rev1);
});

// =========================================================================
//  plan-056 — availability moves the menu feeds even when PRICE does not
// =========================================================================

it('moves the menu feeds when a dish with no priced line is turned off', function () {
    // THE case the catalog revision cannot see. `buildLineSnapshot` only walks
    // rows where BOTH mp.is_active and mps.is_active are true, so a
    // menu_product whose every variant is already off contributes no priced
    // line in EITHER state — the snapshot hash is byte-identical and BR-CR02
    // mints nothing. Before plan-056 the workstation got a 304 here and kept
    // serving a dish the shop had turned off.
    $menuSku = ($this->buildPricedLine)();
    $menuProduct = $menuSku->menuProduct;

    // Take every variant offline first, so no priced line remains either way.
    $menuSku->update(['is_active' => false]);
    $this->travel(4)->seconds();
    $before = ($this->getManifest)()->json('data.feeds.menu');

    $menuProduct->update(['is_active' => false]);
    $this->travel(4)->seconds();

    expect(($this->getManifest)()->json('data.feeds.menu'))->not->toBe($before);
});

it('moves the menu feeds when only the disable REASON is edited', function () {
    // Second blind spot: staff corrects "hết hàng" to "hết nguyên liệu". No row
    // enters or leaves the price map, so the catalog revision stands still —
    // and the POS would keep showing the old words forever.
    $menuSku = ($this->buildPricedLine)();
    $menuProduct = $menuSku->menuProduct;

    $menuProduct->update(['is_active' => false, 'disabled_reason' => 'Hết hàng']);
    $this->travel(4)->seconds();
    $before = ($this->getManifest)()->json('data.feeds.menu');

    $menuProduct->update(['disabled_reason' => 'Hết nguyên liệu']);
    $this->travel(4)->seconds();

    expect(($this->getManifest)()->json('data.feeds.menu'))->not->toBe($before);
});

it('moves the menu feeds when a single VARIANT is turned off', function () {
    $menuSku = ($this->buildPricedLine)();
    $this->travel(4)->seconds();
    $before = ($this->getManifest)()->json('data.feeds.menu');

    $menuSku->update(['is_active' => false]);
    $this->travel(4)->seconds();

    expect(($this->getManifest)()->json('data.feeds.menu'))->not->toBe($before);
});

it('leaves the menu feeds alone when nothing changed', function () {
    // The other half of the contract, and the one that costs money to break: a
    // digest that moves on its own would make every workstation in the fleet
    // re-pull the whole catalog every 5s tick.
    ($this->buildPricedLine)();
    $this->travel(4)->seconds();

    $first = ($this->getManifest)()->json('data.feeds');
    $this->travel(4)->seconds();
    $second = ($this->getManifest)()->json('data.feeds');

    expect($second)->toBe($first);
});

// =========================================================================
//  Memoization — at most one aggregate pass per branch per TTL window
// =========================================================================

/**
 * Count the `max(...)/count(*)` passes SyncManifestService::versionOf runs.
 * `row_count` is its own alias, so nothing else in a request matches.
 */
function aggregatePassesDuring(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $fn();
        $log = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    return count(array_filter($log, fn (array $q): bool => str_contains($q['query'], 'row_count')));
}

it('may serve a stale manifest for the whole TTL window, but never longer', function () {
    $v0 = ($this->getManifest)()->json('data.manifest_version');

    Zone::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);

    // Inside the memo window: the cached manifest is acceptable.
    expect(($this->getManifest)()->json('data.manifest_version'))->toBe($v0);

    // Past the TTL the fresh aggregate MUST be served.
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();
    expect(($this->getManifest)()->json('data.manifest_version'))->not->toBe($v0);
});

/**
 * #2712 A — the idle tick must cost one cache read, not ~25 aggregates.
 *
 * TTL_SECONDS was 3 while the workstation ticks every 5s, so EVERY tick of
 * EVERY workstation missed the memo and rebuilt. The number that matters is
 * therefore not "is there a memo" but "does the memo outlive one tick".
 */
it('rebuilds nothing on a 304 tick that lands one workstation tick after the last build', function () {
    expect(SyncManifestService::TTL_SECONDS)
        ->toBeGreaterThanOrEqual(5, 'the memo must outlive the workstation manifest tick (pullIntervalManifest = 5s)');

    $etag = ($this->getManifest)()->assertOk()->headers->get('ETag');

    // The next tick of the same workstation.
    $this->travel(5)->seconds();

    $passes = aggregatePassesDuring(function () use ($etag) {
        ($this->getManifest)(['If-None-Match' => $etag])->assertStatus(304);
    });

    expect($passes)->toBe(0);
});

it('rebuilds on the very next request after forget(), so a bumped catalog never 304s forever', function () {
    $etag = ($this->getManifest)()->assertOk()->headers->get('ETag');

    // What RebuildCatalogRevisionJob does (#1174). No clock travel: the memo
    // is still inside its TTL and would otherwise answer this request.
    SyncManifestService::forget((string) $this->branch->id);
    Zone::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);

    $rebuilt = null;
    $passes = aggregatePassesDuring(function () use ($etag, &$rebuilt) {
        $rebuilt = ($this->getManifest)(['If-None-Match' => $etag]);
    });

    expect($passes)->toBeGreaterThan(0)
        ->and($rebuilt->status())->toBe(200)
        ->and('"'.$rebuilt->json('data.manifest_version').'"')->not->toBe($etag);
});

// =========================================================================
//  #2712 — the appended feeds carry a version that actually moves
// =========================================================================

it('moves the payment_methods version when a method is added, leaving the other appended feeds still', function () {
    $before = ($this->getManifest)()->json('data.feeds');

    PaymentMethod::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();

    $after = ($this->getManifest)()->json('data.feeds');
    expect($after['payment_methods'])->not->toBe($before['payment_methods'])
        ->and($after['printers'])->toBe($before['printers'])
        ->and($after['till_denominations'])->toBe($before['till_denominations'])
        ->and($after['tender_types'])->toBe($before['tender_types'])
        ->and($after['print_templates'])->toBe($before['print_templates']);
});

it('moves the print_templates version when the brand publishes a template', function () {
    $before = ($this->getManifest)()->json('data.feeds.print_templates');

    PrintTemplate::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => null,
        'kind' => 'receipt',
        'scope' => 'brand',
        'status' => 'published',
    ]);
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();

    expect(($this->getManifest)()->json('data.feeds.print_templates'))->not->toBe($before);
});

it('moves the expected_build version when HQ changes the expected release', function () {
    // The feed is CONFIG, not rows — an aggregate over tables would never see
    // this change, and before #2712 the feed had no caller in manifest mode at
    // all, so the stale-build alert simply never ran.
    config()->set('workstation.expected_build', ['version' => '0.3.0', 'severity' => 'info']);
    $before = ($this->getManifest)()->json('data.feeds.expected_build');

    config()->set('workstation.expected_build', ['version' => '0.4.0', 'severity' => 'info']);
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();

    expect(($this->getManifest)()->json('data.feeds.expected_build'))->not->toBe($before);
});

it('ships the poke broadcast settings in the branch payload only when configured (#1175 P3)', function () {
    // Configured driver → the four frozen keys appear.
    config()->set('broadcasting.default', 'reverb');
    config()->set('broadcasting.connections.reverb', [
        'driver' => 'reverb',
        'key' => 'app-key-1',
        'options' => ['host' => 'reverb.test', 'port' => 443, 'useTLS' => true],
    ]);

    $settings = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])->getJson('/api/v1/workstation/branch')
        ->assertOk()->json('data.settings');

    expect($settings['broadcast_app_key'])->toBe('app-key-1')
        ->and($settings['broadcast_host'])->toBe('reverb.test')
        ->and($settings['broadcast_scheme'])->toBe('https');

    // Missing host (pusher-cloud cluster style) → keys omitted → poke off.
    config()->set('broadcasting.connections.reverb.options.host', '');
    $settings = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])->getJson('/api/v1/workstation/branch')
        ->assertOk()->json('data.settings');
    expect($settings)->not->toHaveKey('broadcast_app_key');
});

it('advertises pusher.com websocket host, not the HTTP API host', function () {
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher', [
        'driver' => 'pusher',
        'key' => 'pusher-key',
        'options' => [
            'cluster' => 'ap3',
            'host' => 'api-ap3.pusher.com',
            'port' => 443,
            'useTLS' => true,
        ],
    ]);

    $settings = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])->getJson('/api/v1/workstation/branch')
        ->assertOk()->json('data.settings');

    expect($settings['broadcast_app_key'])->toBe('pusher-key')
        ->and($settings['broadcast_host'])->toBe('ws-ap3.pusher.com')
        ->and($settings['broadcast_scheme'])->toBe('https');
});

it('moves the branch_settings version when broadcast CONFIG changes — no row moved', function () {
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher', [
        'driver' => 'pusher',
        'key' => 'pusher-key',
        'options' => ['cluster' => 'ap3', 'host' => 'api-ap3.pusher.com', 'port' => 443, 'useTLS' => true],
    ]);

    $before = ($this->getManifest)()->assertOk()->json('data.feeds.branch_settings');

    // A provider/host change deploys as CONFIG, not as a row update. The feed
    // version must move anyway, or every synced workstation 304s forever and
    // keeps dialing the dead host (measured 2026-08-18 on the api-→ws- fix).
    config()->set('broadcasting.connections.pusher.options.cluster', 'ap1');
    $this->travel(SyncManifestService::TTL_SECONDS + 1)->seconds();

    $after = ($this->getManifest)()->assertOk()->json('data.feeds.branch_settings');
    expect($after)->not->toBe($before);
});
