<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PeripheralDevice;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgA = (string) Str::uuid();
    $this->orgB = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->orgA,
        'console_organization_id' => $this->orgA,
    ]);
    Organization::factory()->create([
        'id' => $this->orgB,
        'console_organization_id' => $this->orgB,
    ]);

    $brandA = Brand::factory()->create(['console_organization_id' => $this->orgA]);
    $brandB = Brand::factory()->create(['console_organization_id' => $this->orgB]);

    $this->branchA = Branch::factory()->create([
        'console_organization_id' => $this->orgA,
        'console_brand_id' => $brandA->console_brand_id,
    ]);
    $this->branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgB,
        'console_brand_id' => $brandB->console_brand_id,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgA,
        'branch_id' => $this->branchA->id,
    ]);
});

it('scopes peripheral device replica to workstation branch and organization', function () {
    PeripheralDevice::factory()->create([
        'organization_id' => $this->orgA,
        'branch_id' => $this->branchA->id,
        'name' => 'POS-A',
        'type' => 'pos',
    ]);
    PeripheralDevice::factory()->create([
        'organization_id' => $this->orgA,
        'branch_id' => $this->branchB->id,
        'name' => 'POS-B',
        'type' => 'pos',
    ]);
    PeripheralDevice::factory()->create([
        'organization_id' => $this->orgB,
        'branch_id' => $this->branchB->id,
        'name' => 'POS-OTHER',
        'type' => 'pos',
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/peripheral-devices')
        ->assertOk()
        ->assertJsonStructure([
            'data',
            'generated_at',
        ]);

    $names = collect($response->json('data'))->pluck('name');
    expect($names->values()->all())->toEqual(['POS-A']);
});

it('exposes metadata host and port so the workstation can reach the machine', function () {
    PeripheralDevice::factory()->create([
        'organization_id' => $this->orgA,
        'branch_id' => $this->branchA->id,
        'name' => 'Counter P400',
        'type' => 'payment_terminal',
        'metadata' => ['host' => '192.168.0.77', 'port' => 8888],
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/peripheral-devices')
        ->assertOk();

    $row = collect($response->json('data'))->firstWhere('type', 'payment_terminal');
    expect($row['metadata']['host'])->toBe('192.168.0.77');
    expect($row['metadata']['port'])->toBe(8888);
});

it('lets a workstation register a P400 on its own branch (from the device token)', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/peripheral-devices', [
            'name' => 'Counter P400',
            'type' => 'payment_terminal',
            'metadata' => ['host' => '192.168.0.77', 'port' => 8888],
        ]);

    $response->assertCreated();
    $device = PeripheralDevice::latest('id')->first();

    expect($device->type)->toBe('payment_terminal');
    expect($device->metadata['host'])->toBe('192.168.0.77');
    expect($device->metadata['port'])->toBe(8888);
    // Branch/org come from the device token, not the body.
    expect($device->branch_id)->toBe($this->branchA->id);
    expect($device->organization_id)->toBe($this->orgA);
});

it('upserts by workstation-supplied id so a synced-UP row never duplicates', function () {
    $id = (string) Str::uuid();
    $payload = [
        'id' => $id,
        'name' => 'Offline P400',
        'type' => 'payment_terminal',
        'metadata' => ['host' => '192.168.0.50', 'port' => 8888],
    ];

    // First sync UP → created.
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/peripheral-devices', $payload)
        ->assertCreated();

    // Replay the SAME id with an edited host → updates in place, 200, no dup.
    $payload['metadata']['host'] = '192.168.0.51';
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/peripheral-devices', $payload)
        ->assertOk();

    expect(PeripheralDevice::where('id', $id)->count())->toBe(1);
    expect(PeripheralDevice::find($id)->metadata['host'])->toBe('192.168.0.51');
});

it('preserves free-form metadata keys like a Glory cash changer model', function () {
    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/peripheral-devices', [
            'name' => 'Counter Glory',
            'type' => 'coin_changer',
            'metadata' => ['host' => '192.168.0.10', 'port' => 80, 'model' => 'RT-RAD-300'],
        ]);

    $response->assertCreated();
    $device = PeripheralDevice::latest('id')->first();
    // validated() would have stripped `model` (no rule) — the controller keeps
    // the full metadata so it survives the sync round-trip.
    expect($device->metadata['model'])->toBe('RT-RAD-300');
    expect($device->metadata['host'])->toBe('192.168.0.10');
});

it('rejects a workstation-registered LAN device with no host', function () {
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/peripheral-devices', [
            'name' => 'Hostless',
            'type' => 'coin_changer',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['metadata.host']);
});

it('lets a workstation update and delete its own branch peripheral', function () {
    $device = PeripheralDevice::factory()->create([
        'organization_id' => $this->orgA,
        'branch_id' => $this->branchA->id,
        'name' => 'Counter Changer',
        'type' => 'coin_changer',
        'metadata' => ['host' => '192.168.0.10'],
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->putJson("/api/v1/workstation/peripheral-devices/{$device->id}", [
            'metadata' => ['host' => '192.168.0.20', 'port' => 8080],
        ])
        ->assertOk();
    expect($device->fresh()->metadata['host'])->toBe('192.168.0.20');

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->deleteJson("/api/v1/workstation/peripheral-devices/{$device->id}")
        ->assertNoContent();
    expect($device->fresh()->deleted_at)->not->toBeNull();
});

it('cannot touch a peripheral from another branch', function () {
    $other = PeripheralDevice::factory()->create([
        'organization_id' => $this->orgB,
        'branch_id' => $this->branchB->id,
        'name' => 'Other Branch P400',
        'type' => 'payment_terminal',
        'metadata' => ['host' => '10.0.0.9'],
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->putJson("/api/v1/workstation/peripheral-devices/{$other->id}", ['is_active' => false])
        ->assertNotFound();

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->deleteJson("/api/v1/workstation/peripheral-devices/{$other->id}")
        ->assertNotFound();
});

it('does not expose secret in replica payload', function () {
    PeripheralDevice::factory()->create([
        'organization_id' => $this->orgA,
        'branch_id' => $this->branchA->id,
        'name' => 'Secure Terminal',
        'type' => 'payment_terminal',
        'secret' => 'should-not-expose',
    ]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/peripheral-devices')
        ->assertOk();

    expect(collect($response->json('data'))->first()['secret'] ?? null)->toBeNull();
    expect($response->json('data')[0]['name'])->toBe('Secure Terminal');
});

it('rejects a store that reuses another branch peripheral id', function () {
    $other = PeripheralDevice::factory()->create([
        'organization_id' => $this->orgB,
        'branch_id' => $this->branchB->id,
        'name' => 'Other Branch P400',
        'type' => 'payment_terminal',
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/peripheral-devices', [
            'id' => $other->id,
            'name' => 'Hijack',
            'type' => 'payment_terminal',
            'metadata' => ['host' => '10.0.0.5', 'port' => 9001],
        ])
        ->assertConflict();

    expect($other->fresh()->branch_id)->toBe($this->branchB->id);
    expect($other->fresh()->name)->toBe('Other Branch P400');
});
