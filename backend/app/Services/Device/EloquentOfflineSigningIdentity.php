<?php

declare(strict_types=1);

namespace App\Services\Device;

use App\Models\Device;
use App\Models\DeviceSigningKey;
use App\Services\Order\Contracts\OfflineSigningIdentity;
use App\Services\Order\Contracts\SigningDeviceIdentity;
use App\Services\Order\Contracts\SigningKeyEvidence;
use Carbon\CarbonImmutable;

/**
 * PlatformIntegration side of {@see OfflineSigningIdentity} (#962).
 *
 * Two primary-key reads, nothing else. The validity RULE is not restated here:
 * it is delegated to {@see DeviceSigningKeyService::wasValidAt}, which stays the
 * one place that decides whether a key could vouch for money at a given instant
 * (BR-DSK01/02). This class only decides what the order half is allowed to see.
 */
final class EloquentOfflineSigningIdentity implements OfflineSigningIdentity
{
    public function __construct(
        private readonly DeviceSigningKeyService $signingKeys = new DeviceSigningKeyService,
    ) {}

    public function findSigningKey(string $keyId, CarbonImmutable $signedAt): ?SigningKeyEvidence
    {
        /** @var DeviceSigningKey|null $key */
        $key = DeviceSigningKey::query()->find($keyId);

        if ($key === null) {
            return null;
        }

        return new SigningKeyEvidence(
            id: (string) $key->id,
            deviceId: (string) $key->device_id,
            publicKey: (string) $key->public_key,
            revokedAt: $key->revoked_at === null ? null : (string) $key->revoked_at,
            revokedReason: $key->revoked_reason === null ? null : (string) $key->revoked_reason,
            issuedAt: $key->issued_at === null ? null : (string) $key->issued_at,
            expiresAt: $key->expires_at === null ? null : (string) $key->expires_at,
            validAtSignature: $this->signingKeys->wasValidAt($key, $signedAt),
        );
    }

    public function findSigningDevice(string $deviceId): ?SigningDeviceIdentity
    {
        /** @var Device|null $device */
        $device = Device::query()->find($deviceId);

        if ($device === null) {
            return null;
        }

        return new SigningDeviceIdentity(
            id: (string) $device->id,
            branchId: $device->branch_id === null ? null : (string) $device->branch_id,
            organizationId: $device->organization_id === null ? null : (string) $device->organization_id,
        );
    }
}
