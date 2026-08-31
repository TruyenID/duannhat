<?php

/**
 * #1208 item 6 — the Reverb server must recognise the per-brand app keys this
 * codebase provisions and hands to admin-web + KDS.
 *
 * Before this driver existed, `config/reverb.php` used the `config` provider,
 * so the only app the running server knew was the one built from `.env`. Every
 * brand key served by the two ReverbConfigControllers was therefore rejected at
 * handshake, and a brand with no key made those endpoints answer
 * `app_key: null`. Neither branch could connect: staff realtime never arrived,
 * and nothing said so.
 *
 * Pinned here, in both directions:
 *   - the `.env` app still resolves (the driver is additive, not a swap);
 *   - a brand's provisioned key now resolves, by key and by id;
 *   - an unknown key is still rejected — it must not become permissive.
 */

use App\Models\Brand;
use App\Models\Organization;
use App\Services\Notification\BrandApplicationProvider;
use Illuminate\Support\Str;
use Laravel\Reverb\ApplicationManager;
use Laravel\Reverb\Contracts\ApplicationProvider;
use Laravel\Reverb\Exceptions\InvalidApplication;

beforeEach(function () {
    config()->set('reverb.apps.apps', [[
        'key' => 'env-app-key',
        'secret' => 'env-app-secret',
        'app_id' => 'env-app-id',
        'options' => [],
        'allowed_origins' => ['*'],
        'ping_interval' => 60,
        'activity_timeout' => 30,
        'max_connections' => null,
        'max_message_size' => 10_000,
        'accept_client_events_from' => 'members',
        'rate_limiting' => null,
    ]]);

    $this->provider = new BrandApplicationProvider(
        app(ApplicationManager::class)->createConfigDriver()
    );

    $orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $orgId,
        'console_organization_id' => $orgId,
    ]);
    // `Brand::created` hook provisions key/secret/app_id on create — the very rows the
    // server used to reject. Nothing is stubbed here on purpose.
    $this->brand = Brand::factory()->create(['console_organization_id' => $orgId]);
});

test('the .env app still resolves — the driver is additive', function () {
    expect($this->provider->findByKey('env-app-key')->id())->toBe('env-app-id')
        ->and($this->provider->findById('env-app-id')->key())->toBe('env-app-key');
});

test('a brand provisioned key resolves, by key and by id', function () {
    $brand = $this->brand->fresh();

    expect($brand->reverb_app_key)->not->toBeNull()
        ->and($brand->reverb_app_id)->not->toBeNull();

    $byKey = $this->provider->findByKey($brand->reverb_app_key);
    $byId = $this->provider->findById($brand->reverb_app_id);

    expect($byKey->id())->toBe($brand->reverb_app_id)
        ->and($byKey->secret())->toBe($brand->reverb_app_secret)
        ->and($byId->key())->toBe($brand->reverb_app_key);
});

test('an unknown key is still rejected', function () {
    expect(fn () => $this->provider->findByKey('no-such-key'))
        ->toThrow(InvalidApplication::class);

    expect(fn () => $this->provider->findById('no-such-id'))
        ->toThrow(InvalidApplication::class);
});

test('all() lists the config app alongside every provisioned brand', function () {
    $ids = $this->provider->all()->map(fn ($app) => $app->id())->all();

    expect($ids)->toContain('env-app-id')
        ->and($ids)->toContain($this->brand->fresh()->reverb_app_id);
});

test('the container resolves the database driver by default', function () {
    // The bug was not that the class was missing — it is that the running
    // server never reached one. Pin the wiring, not just the class.
    expect(config('reverb.apps.provider'))->toBe('database')
        ->and(app(ApplicationProvider::class))->toBeInstanceOf(BrandApplicationProvider::class);
});

test('the config driver alone rejects a brand key — this was the bug', function () {
    // Not a hypothetical: `provider => 'config'` was the shipped setting, and
    // both ReverbConfigControllers hand this exact key to admin-web and KDS.
    $configOnly = app(ApplicationManager::class)->createConfigDriver();

    expect(fn () => $configOnly->findByKey($this->brand->fresh()->reverb_app_key))
        ->toThrow(InvalidApplication::class);

    // Same key, same moment, through the driver that now runs: accepted.
    expect($this->provider->findByKey($this->brand->fresh()->reverb_app_key)->id())
        ->toBe($this->brand->fresh()->reverb_app_id);
});
