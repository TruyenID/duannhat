<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Services\Device\DeviceService;
use App\Services\Device\DeviceSigningKeyService;
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
    $this->wsToken = Str::random(64);
    $this->wsDevice = Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'paired_at' => now()->subHours(2),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

it('requires a device token', function () {
    $this->postJson('/api/v1/workstation/self-revoke')->assertUnauthorized();
});

it('revokes the calling device and clears its token', function () {
    $response = $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
    ])->postJson('/api/v1/workstation/self-revoke');

    $response->assertOk()
        ->assertJsonPath('status', 'revoked')
        ->assertJsonPath('device_id', $this->wsDevice->id);

    $this->wsDevice->refresh();
    $status = $this->wsDevice->status instanceof BackedEnum
        ? $this->wsDevice->status->value
        : $this->wsDevice->status;
    expect($status)->toBe(DeviceStatusEnum::Revoked->value);
    expect($this->wsDevice->device_token)->toBeNull();
    expect($this->wsDevice->paired_at)->toBeNull();
});

it('rejects subsequent calls using the now-revoked token', function () {
    // First revoke succeeds.
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/self-revoke')
        ->assertOk();

    // Second call to any /workstation/* endpoint with same (revoked) token → 401.
    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->getJson('/api/v1/workstation/menu')
        ->assertUnauthorized();
});

it('revokes the signing keys in the same act as the pairing', function () {
    $key = app(DeviceSigningKeyService::class)->issue(
        $this->wsDevice,
        base64_encode(random_bytes(32)),
    );

    $this->withHeaders(['Authorization' => "Bearer {$this->wsToken}"])
        ->postJson('/api/v1/workstation/self-revoke')
        ->assertOk();

    expect($key->refresh()->revoked_at)->not->toBeNull()
        ->and($key->revoked_reason)->toBe('device_self_revoked');
});

/**
 * #1669 — this ran as two unrelated writes, keys first and device second, so a
 * failure between them left the keys revoked while the device stayed `active`
 * holding a working token: it could still call every /workstation/* endpoint,
 * just not sign an offline order. Nothing watches for that half-state, which is
 * what makes it worth a test rather than a comment.
 *
 * Driven at the service, not over HTTP: the fault has to land BETWEEN the two
 * writes, and the only seam that lets a test choose that point is the signing
 * key service the transaction calls second.
 */
it('leaves the device fully paired when revoking its keys fails', function () {
    $key = app(DeviceSigningKeyService::class)->issue(
        $this->wsDevice,
        base64_encode(random_bytes(32)),
    );

    $this->app->bind(DeviceSigningKeyService::class, fn () => new class extends DeviceSigningKeyService
    {
        public function revokeAllFor(Device $device, string $reason): int
        {
            throw new RuntimeException('signing key store unavailable');
        }
    });

    expect(fn () => app(DeviceService::class)->selfRevoke($this->wsDevice))
        ->toThrow(RuntimeException::class);

    $this->wsDevice->refresh();
    $status = $this->wsDevice->status instanceof BackedEnum
        ? $this->wsDevice->status->value
        : $this->wsDevice->status;

    // Rolled back: a device that is still trusted must still be usable.
    expect($status)->toBe('active')
        ->and($this->wsDevice->device_token)->toBe($this->wsToken)
        ->and($this->wsDevice->paired_at)->not->toBeNull()
        ->and($key->refresh()->revoked_at)->toBeNull();
});
