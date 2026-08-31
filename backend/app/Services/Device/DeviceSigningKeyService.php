<?php

namespace App\Services\Device;

use App\Models\Device;
use App\Models\DeviceSigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ed25519 device signing keys (#1092/#1093 — offline-order evidence, 1/5).
 *
 * The DEVICE generates the keypair and registers only the public half here
 * (at pair time or via rotation); the private key never leaves the device.
 * These rows are what the offline-replay verifier (#1096) checks signatures
 * against before trusting any offline money claim — so validity is strict:
 * un-revoked AND un-expired, nothing else counts (BR-DSK01/02).
 */
class DeviceSigningKeyService
{
    /** Default key lifetime. Rotation issues a fresh key — never extends. */
    public const LIFETIME_DAYS = 180;

    /**
     * Register a device-generated public key. Used by both first issuance
     * (pair) and rotation — rotation deliberately does NOT revoke the previous
     * key: orders signed offline before the rotation must still verify when
     * they sync UP, so the old key stays valid until its own expires_at
     * (grace window, BR-DSK01).
     */
    public function issue(Device $device, string $publicKey): DeviceSigningKey
    {
        $this->assertEd25519PublicKey($publicKey);

        $now = CarbonImmutable::now();

        return DeviceSigningKey::create([
            'device_id' => $device->id,
            'organization_id' => $device->organization_id,
            'public_key' => $publicKey,
            'issued_at' => $now,
            'expires_at' => $now->addDays(self::LIFETIME_DAYS),
        ]);
    }

    /**
     * Revoke every active key of a device (BR-DSK03) — self-revoke/unpair,
     * admin device revoke, or a compromise response. Immediate and
     * irreversible: signatures made with these keys are rejected even inside
     * the grace window (BR-DSK02).
     *
     * @return int number of keys revoked
     */
    public function revokeAllFor(Device $device, string $reason): int
    {
        return DB::transaction(fn (): int => DeviceSigningKey::query()
            ->where('device_id', $device->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => CarbonImmutable::now(),
                'revoked_reason' => mb_substr($reason, 0, 255),
            ]));
    }

    /** Revoke a single key by id (HQ admin action). Idempotent. */
    public function revoke(DeviceSigningKey $key, string $reason): DeviceSigningKey
    {
        if ($key->revoked_at === null) {
            $key->update([
                'revoked_at' => CarbonImmutable::now(),
                'revoked_reason' => mb_substr($reason, 0, 255),
            ]);
        }

        return $key;
    }

    /** Query scope: keys currently usable for signature verification. */
    public function validKeysFor(Device $device): Builder
    {
        return DeviceSigningKey::query()
            ->where('device_id', $device->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', CarbonImmutable::now());
    }

    /**
     * Was this key valid AT a given instant? The verifier (#1096) checks
     * validity at the evidence's issued_at — but a revoked key fails for
     * every instant, past included (BR-DSK02: revoke = compromised, and a
     * compromised key's timestamps cannot be trusted to date the signature).
     */
    public function wasValidAt(DeviceSigningKey $key, CarbonImmutable $at): bool
    {
        return $key->revoked_at === null
            && $at->gte(CarbonImmutable::parse($key->issued_at))
            && $at->lt(CarbonImmutable::parse($key->expires_at));
    }

    /**
     * An Ed25519 public key is exactly 32 bytes; the wire format is its
     * standard base64 encoding (44 chars, one '=' pad). Reject anything else
     * up front — a malformed key stored today is a verification outage later.
     */
    private function assertEd25519PublicKey(string $publicKey): void
    {
        $decoded = base64_decode($publicKey, true);

        if ($decoded === false || strlen($decoded) !== 32 || base64_encode($decoded) !== $publicKey) {
            throw ValidationException::withMessages([
                'public_key' => [__('public_key must be a base64-encoded 32-byte Ed25519 public key.')],
            ]);
        }
    }
}
