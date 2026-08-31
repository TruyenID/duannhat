<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
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

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->deviceToken = Str::random(32);

    $this->device = Device::factory()->create([
        'type' => 'kiosk',
        'status' => 'active',
        'device_token' => $this->deviceToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('records a kiosk audit-log event with device_id + branch_id captured into metadata', function () {
    $paymentId = (string) Str::uuid();

    $response = $this->withHeader('Authorization', "Bearer {$this->deviceToken}")
        ->postJson('/api/v1/kiosk/audit-logs', [
            'event' => 'payment.initiated',
            'auditable_type' => 'OrderPayment',
            'auditable_id' => $paymentId,
            'metadata' => [
                'amount' => 1500,
                'method' => 'card',
            ],
        ]);

    $response->assertStatus(201);
    $response->assertJsonStructure(['data' => ['id', 'recorded_at']]);

    $this->assertDatabaseHas('audit_logs', [
        'auditable_type' => 'OrderPayment',
        'auditable_id' => $paymentId,
        'action' => 'payment.initiated',
        'user_id' => null,
    ]);

    $log = AuditLog::where('auditable_id', $paymentId)->first();
    expect($log)->not->toBeNull();
    expect($log->metadata)->toMatchArray([
        'amount' => 1500,
        'method' => 'card',
        'device_id' => $this->device->id,
        'branch_id' => $this->branch->id,
        'source' => 'kiosk',
    ]);
});

it('accepts the kiosk logical types and normalizes them to morph aliases', function () {
    $cases = [
        ['Payment', 'OrderPayment'],
        ['Order', 'CustomerOrder'],
        ['Terminal', 'Device'],
        ['Device', 'Device'],
    ];

    foreach ($cases as [$sent, $stored]) {
        $id = (string) Str::uuid();
        $this->withHeader('Authorization', "Bearer {$this->deviceToken}")
            ->postJson('/api/v1/kiosk/audit-logs', [
                'event' => 'payment.initiated',
                'auditable_type' => $sent,
                'auditable_id' => $id,
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => $stored,
            'auditable_id' => $id,
        ]);
    }
});

it('rejects an audit-log event without a Bearer token', function () {
    $response = $this->postJson('/api/v1/kiosk/audit-logs', [
        'event' => 'payment.initiated',
        'auditable_type' => 'OrderPayment',
        'auditable_id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(401);
});

it('rejects a payload without required fields', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->deviceToken}")
        ->postJson('/api/v1/kiosk/audit-logs', [
            // missing event, auditable_type, auditable_id
            'metadata' => ['x' => 1],
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['event', 'auditable_type', 'auditable_id']);
});

it('allows an empty metadata block', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->deviceToken}")
        ->postJson('/api/v1/kiosk/audit-logs', [
            'event' => 'payment.crash',
            'auditable_type' => 'Device',
            'auditable_id' => $this->device->id,
        ]);

    $response->assertStatus(201);

    $log = AuditLog::where('action', 'payment.crash')
        ->where('auditable_id', $this->device->id)
        ->first();
    expect($log)->not->toBeNull();
    // metadata still includes the device_id/branch_id/source overlay
    expect($log->metadata)->toMatchArray([
        'device_id' => $this->device->id,
        'branch_id' => $this->branch->id,
        'source' => 'kiosk',
    ]);
});

it('rejects an auditable_type that is not in the morph map', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->deviceToken}")
        ->postJson('/api/v1/kiosk/audit-logs', [
            'event' => 'payment.initiated',
            'auditable_type' => 'NotARealModel',
            'auditable_id' => (string) Str::uuid(),
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['auditable_type']);
});

it('rejects metadata containing a digit run that looks like a card number', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->deviceToken}")
        ->postJson('/api/v1/kiosk/audit-logs', [
            'event' => 'payment.failed',
            'auditable_type' => 'OrderPayment',
            'auditable_id' => (string) Str::uuid(),
            'metadata' => [
                'note' => 'card declined 4111111111111111',
            ],
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['metadata']);
});

it('rejects metadata payload over 16 KB', function () {
    $bigBlob = str_repeat('a', 17 * 1024);

    $response = $this->withHeader('Authorization', "Bearer {$this->deviceToken}")
        ->postJson('/api/v1/kiosk/audit-logs', [
            'event' => 'payment.crash',
            'auditable_type' => 'Device',
            'auditable_id' => $this->device->id,
            'metadata' => ['stack' => $bigBlob],
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['metadata']);
});

it('lets the authenticated device override a client-forged device_id in metadata', function () {
    $anotherDeviceId = (string) Str::uuid();

    $response = $this->withHeader('Authorization', "Bearer {$this->deviceToken}")
        ->postJson('/api/v1/kiosk/audit-logs', [
            'event' => 'payment.confirmed',
            'auditable_type' => 'OrderPayment',
            'auditable_id' => (string) Str::uuid(),
            'metadata' => [
                'device_id' => $anotherDeviceId,
                'note' => 'attempted spoof',
            ],
        ]);

    $response->assertStatus(201);

    $log = AuditLog::latest('created_at')->first();
    expect($log->metadata['device_id'])->toBe($this->device->id);
    expect($log->metadata['device_id'])->not->toBe($anotherDeviceId);
    expect($log->metadata['note'])->toBe('attempted spoof');
});
