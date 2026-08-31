<?php

/**
 * BrandAwareBroadcastManager tests (plan-012 T4.5).
 */

use App\Broadcasting\BrandAwareBroadcastManager;
use App\Models\Brand;
use App\Models\Organization;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

class StubBroadcastEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $payload) {}

    public function broadcastOn(): array
    {
        return [];
    }
}

beforeEach(function () {
    BrandAwareBroadcastManager::$recorded = [];
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
});

it('records per-brand app_id on each broadcast', function () {
    $brandA = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'ba-'.Str::random(4),
        'is_active' => true,
    ]);
    $brandB = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'bb-'.Str::random(4),
        'is_active' => true,
    ]);
    $brandA->refresh();
    $brandB->refresh();

    $manager = app(BrandAwareBroadcastManager::class);
    $manager->brand($brandA)->broadcast(new StubBroadcastEvent('first'));
    $manager->brand($brandB)->broadcast(new StubBroadcastEvent('second'));

    $appIds = collect(BrandAwareBroadcastManager::$recorded)->pluck('app_id')->all();
    expect($appIds)->toBe([$brandA->reverb_app_id, $brandB->reverb_app_id]);
});

it('unbranded broadcast records a null app_id but still routes through the underlying manager', function () {
    app(BrandAwareBroadcastManager::class)->broadcast(new StubBroadcastEvent('unscoped'));

    $entry = BrandAwareBroadcastManager::$recorded[0] ?? null;
    expect($entry)->not->toBeNull();
    expect($entry['app_id'])->toBeNull();
});

it('restores the previous reverb config after a scoped broadcast', function () {
    config([
        'broadcasting.connections.reverb.app_id' => 'bootstrap',
        'broadcasting.connections.reverb.key' => 'bootstrap-key',
        'broadcasting.connections.reverb.secret' => 'bootstrap-secret',
    ]);

    $brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'br-'.Str::random(4),
        'is_active' => true,
    ]);
    $brand->refresh();

    app(BrandAwareBroadcastManager::class)->brand($brand)->broadcast(new StubBroadcastEvent('x'));

    expect(config('broadcasting.connections.reverb.app_id'))->toBe('bootstrap');
    expect(config('broadcasting.connections.reverb.key'))->toBe('bootstrap-key');
    expect(config('broadcasting.connections.reverb.secret'))->toBe('bootstrap-secret');
});
