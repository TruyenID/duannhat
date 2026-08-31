<?php

namespace App\Services\Payment\Secret;

use App\Services\Payment\Gateway\ValueObjects\EphemeralSecret;
use App\Services\Payment\Secret\Contracts\GatewayMasterKeyProvider;
use App\Services\Payment\Secret\Contracts\GatewaySecretStoreContract;
use App\Services\Payment\Secret\Enums\GatewaySecretPurpose;
use App\Services\Payment\Secret\Exceptions\GatewaySecretResolutionFailed;
use App\Services\Payment\Secret\ValueObjects\GatewayMasterKey;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretAccessContext;
use App\Services\Payment\Secret\ValueObjects\GatewaySecretRotationResult;
use App\Services\Payment\Secret\ValueObjects\ResolvedGatewaySecret;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use SensitiveParameter;
use SodiumException;

final class DatabaseGatewaySecretStore implements GatewaySecretStoreContract
{
    private const MAX_WEBHOOK_OVERLAP_SECONDS = 86_400;

    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly GatewayMasterKeyProvider $keys,
        private readonly GatewaySecretAuditProtection $auditProtection,
    ) {}

    public function resolveCurrent(
        GatewaySecretAccessContext $context,
        GatewaySecretPurpose $purpose,
    ): ResolvedGatewaySecret {
        $connection = $this->authorizedConnection($context);
        $reference = $connection->{$this->referenceColumn($purpose)} ?? null;

        if (! is_string($reference)) {
            throw new GatewaySecretResolutionFailed($context->correlationId);
        }

        $row = $this->authorizedSecretQuery($context, $purpose)
            ->where('id', $reference)
            ->where('state', 'active')
            ->first();

        if ($row === null) {
            throw new GatewaySecretResolutionFailed($context->correlationId);
        }

        return $this->decrypt($row, $context);
    }

    public function resolveWebhookCandidates(GatewaySecretAccessContext $context): array
    {
        $connection = $this->authorizedConnection($context);
        if (! is_string($connection->webhook_secret_ref ?? null)) {
            throw new GatewaySecretResolutionFailed($context->correlationId);
        }

        $now = CarbonImmutable::now('UTC');
        $rows = $this->authorizedSecretQuery($context, GatewaySecretPurpose::Webhook)
            ->where(function ($query) use ($connection, $now): void {
                $query->where(function ($active) use ($connection): void {
                    $active->where('id', $connection->webhook_secret_ref)->where('state', 'active');
                })->orWhere(function ($retiring) use ($now): void {
                    $retiring->where('state', 'retiring')->where('valid_until', '>', $now);
                });
            })
            ->orderByDesc('version')
            ->get();

        if ($rows->isEmpty() || $rows->first()->id !== $connection->webhook_secret_ref) {
            throw new GatewaySecretResolutionFailed($context->correlationId);
        }

        return $rows->map(fn (object $row): ResolvedGatewaySecret => $this->decrypt($row, $context))->all();
    }

    public function rotate(
        GatewaySecretAccessContext $context,
        GatewaySecretPurpose $purpose,
        EphemeralSecret $newSecret,
        int $webhookOverlapSeconds = 0,
    ): GatewaySecretRotationResult {
        $this->auditProtection->assertInstalled();

        if ($webhookOverlapSeconds < 0
            || $webhookOverlapSeconds > self::MAX_WEBHOOK_OVERLAP_SECONDS
            || ($purpose !== GatewaySecretPurpose::Webhook && $webhookOverlapSeconds !== 0)) {
            throw new InvalidArgumentException('Gateway secret overlap is invalid.');
        }

        $plaintext = $newSecret->reveal();
        if ($plaintext === '' || strlen($plaintext) > 16_384) {
            throw new InvalidArgumentException('Gateway secret length is invalid.');
        }

        return $this->database->transaction(function () use ($context, $purpose, $plaintext, $webhookOverlapSeconds): GatewaySecretRotationResult {
            $connection = $this->authorizedConnection($context, true);
            $referenceColumn = $this->referenceColumn($purpose);
            $oldReference = is_string($connection->{$referenceColumn} ?? null) ? $connection->{$referenceColumn} : null;
            $oldRow = $oldReference === null ? null : $this->authorizedSecretQuery($context, $purpose)
                ->where('id', $oldReference)->lockForUpdate()->first();
            if ($oldReference !== null && ($oldRow === null || $oldRow->state !== 'active')) {
                throw new GatewaySecretResolutionFailed($context->correlationId);
            }
            $version = ((int) $this->authorizedSecretQuery($context, $purpose)->max('version')) + 1;
            $reference = (string) Str::uuid();
            $key = $this->keys->active();
            $createdAt = CarbonImmutable::now('UTC');
            $overlapUntil = $purpose === GatewaySecretPurpose::Webhook && $webhookOverlapSeconds > 0
                ? $createdAt->addSeconds($webhookOverlapSeconds)
                : null;
            [$nonce, $ciphertext, $fingerprint] = $this->encrypt(
                $plaintext,
                $context,
                $purpose,
                $version,
                $reference,
                $key,
            );

            if ($purpose === GatewaySecretPurpose::Webhook) {
                $this->authorizedSecretQuery($context, $purpose)
                    ->where('state', 'retiring')
                    ->update(['state' => 'revoked', 'valid_until' => null, 'revoked_at' => $createdAt]);
            }

            if ($oldRow !== null) {
                $this->database->table('payment_gateway_secret_versions')->where('id', $oldReference)->update([
                    'state' => $overlapUntil === null ? 'revoked' : 'retiring',
                    'valid_until' => $overlapUntil,
                    'revoked_at' => $overlapUntil === null ? $createdAt : null,
                ]);
            }

            $this->database->table('payment_gateway_secret_versions')->insert([
                'id' => $reference,
                'organization_id' => $context->organizationId,
                'connection_id' => $context->connectionId,
                'provider_code' => $context->provider->value,
                'environment' => $context->environment->value,
                'purpose' => $purpose->value,
                'version' => $version,
                'key_id' => $key->keyId,
                'nonce' => $nonce,
                'ciphertext' => $ciphertext,
                'fingerprint' => $fingerprint,
                'state' => 'active',
                'valid_until' => null,
                'created_at' => $createdAt,
                'revoked_at' => null,
            ]);

            $connectionUpdate = [$referenceColumn => $reference, 'updated_at' => $createdAt];
            if ($purpose === GatewaySecretPurpose::Api) {
                $connectionUpdate['secret_version'] = (string) $version;
                $connectionUpdate['key_fingerprint'] = $fingerprint;
            }
            $this->database->table('payment_gateway_connections')->where('id', $context->connectionId)->update($connectionUpdate);

            $this->audit(
                $context,
                $purpose,
                $oldRow === null ? 'created' : 'rotated',
                $oldRow?->version,
                $version,
                $key->keyId,
                $oldReference,
                $reference,
                $oldRow?->fingerprint,
                $fingerprint,
                $overlapUntil,
                $createdAt,
            );

            return new GatewaySecretRotationResult($purpose, $version, $fingerprint, $key->keyId, $overlapUntil);
        }, 3);
    }

    public function revoke(GatewaySecretAccessContext $context, GatewaySecretPurpose $purpose): void
    {
        $this->auditProtection->assertInstalled();

        $this->database->transaction(function () use ($context, $purpose): void {
            $connection = $this->authorizedConnection($context, true);
            $referenceColumn = $this->referenceColumn($purpose);
            $oldReference = is_string($connection->{$referenceColumn} ?? null) ? $connection->{$referenceColumn} : null;
            $now = CarbonImmutable::now('UTC');
            $oldRow = $oldReference === null ? null : $this->authorizedSecretQuery($context, $purpose)
                ->where('id', $oldReference)->lockForUpdate()->first();

            $this->authorizedSecretQuery($context, $purpose)
                ->whereIn('state', ['active', 'retiring'])
                ->update(['state' => 'revoked', 'valid_until' => null, 'revoked_at' => $now]);

            $connectionUpdate = [$referenceColumn => null, 'updated_at' => $now];
            if ($purpose === GatewaySecretPurpose::Api) {
                $connectionUpdate['secret_version'] = null;
                $connectionUpdate['key_fingerprint'] = null;
            }
            $this->database->table('payment_gateway_connections')->where('id', $context->connectionId)->update($connectionUpdate);

            $this->audit(
                $context,
                $purpose,
                'revoked',
                $oldRow?->version,
                null,
                $oldRow?->key_id,
                $oldReference,
                null,
                $oldRow?->fingerprint,
                null,
                null,
                $now,
            );
        }, 3);
    }

    private function authorizedConnection(GatewaySecretAccessContext $context, bool $lock = false): object
    {
        $query = $this->database->table('payment_gateway_connections as connections')
            ->join('payment_gateway_providers as providers', 'providers.id', '=', 'connections.provider_id')
            ->where('connections.id', $context->connectionId)
            ->where('connections.organization_id', $context->organizationId)
            ->where('connections.environment', $context->environment->value)
            ->where('providers.code', $context->provider->value)
            ->whereNull('connections.deleted_at')
            ->select('connections.*', 'providers.code as provider_code');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new GatewaySecretResolutionFailed($context->correlationId);
    }

    private function authorizedSecretQuery(GatewaySecretAccessContext $context, GatewaySecretPurpose $purpose)
    {
        return $this->database->table('payment_gateway_secret_versions')
            ->where('organization_id', $context->organizationId)
            ->where('connection_id', $context->connectionId)
            ->where('provider_code', $context->provider->value)
            ->where('environment', $context->environment->value)
            ->where('purpose', $purpose->value);
    }

    private function decrypt(object $row, GatewaySecretAccessContext $context): ResolvedGatewaySecret
    {
        $keyBytes = null;

        try {
            $key = $this->keys->byId($row->key_id);
            $keyBytes = $key->bytes();
            $ciphertext = base64_decode($row->ciphertext, true);
            $nonce = base64_decode($row->nonce, true);
            if ($ciphertext === false || $nonce === false || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
                throw new SodiumException('Invalid ciphertext encoding.');
            }
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $this->associatedData($context, GatewaySecretPurpose::from($row->purpose), (int) $row->version, $row->id, $row->key_id),
                $nonce,
                $keyBytes,
            );
            if ($plaintext === false || ! hash_equals($row->fingerprint, $this->fingerprint($plaintext, $keyBytes))) {
                throw new SodiumException('Gateway secret authentication failed.');
            }
        } catch (\Throwable) {
            throw new GatewaySecretResolutionFailed($context->correlationId);
        } finally {
            if (is_string($keyBytes)) {
                sodium_memzero($keyBytes);
            }
        }

        return new ResolvedGatewaySecret(
            GatewaySecretPurpose::from($row->purpose),
            (int) $row->version,
            $row->fingerprint,
            $plaintext,
        );
    }

    /** @return array{string, string, string} */
    private function encrypt(
        #[SensitiveParameter] string $plaintext,
        GatewaySecretAccessContext $context,
        GatewaySecretPurpose $purpose,
        int $version,
        string $reference,
        GatewayMasterKey $key,
    ): array {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $keyBytes = $key->bytes();
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $this->associatedData($context, $purpose, $version, $reference, $key->keyId),
            $nonce,
            $keyBytes,
        );
        $fingerprint = $this->fingerprint($plaintext, $keyBytes);
        sodium_memzero($keyBytes);

        return [base64_encode($nonce), base64_encode($ciphertext), $fingerprint];
    }

    private function fingerprint(#[SensitiveParameter] string $plaintext, string $keyBytes): string
    {
        $fingerprintKey = sodium_crypto_generichash('payment-gateway-secret-fingerprint-v1', $keyBytes, 32);

        try {
            return hash_hmac('sha256', $plaintext, $fingerprintKey);
        } finally {
            sodium_memzero($fingerprintKey);
        }
    }

    private function associatedData(
        GatewaySecretAccessContext $context,
        GatewaySecretPurpose $purpose,
        int $version,
        string $reference,
        string $keyId,
    ): string {
        return json_encode([
            'schema' => 'payment-gateway-secret-v1',
            'organization_id' => $context->organizationId,
            'connection_id' => $context->connectionId,
            'provider' => $context->provider->value,
            'environment' => $context->environment->value,
            'purpose' => $purpose->value,
            'version' => $version,
            'reference' => $reference,
            'key_id' => $keyId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function referenceColumn(GatewaySecretPurpose $purpose): string
    {
        return $purpose === GatewaySecretPurpose::Api ? 'secret_ref' : 'webhook_secret_ref';
    }

    private function audit(
        GatewaySecretAccessContext $context,
        GatewaySecretPurpose $purpose,
        string $action,
        ?int $oldVersion,
        ?int $newVersion,
        ?string $keyId,
        ?string $oldReference,
        ?string $newReference,
        ?string $oldFingerprint,
        ?string $newFingerprint,
        ?CarbonImmutable $overlapUntil,
        CarbonImmutable $createdAt,
    ): void {
        $this->database->table('payment_gateway_secret_audits')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $context->organizationId,
            'connection_id' => $context->connectionId,
            'provider_code' => $context->provider->value,
            'environment' => $context->environment->value,
            'purpose' => $purpose->value,
            'action' => $action,
            'old_version' => $oldVersion,
            'new_version' => $newVersion,
            'key_id' => $keyId,
            'old_ref_hash' => $oldReference === null ? null : hash('sha256', $oldReference),
            'new_ref_hash' => $newReference === null ? null : hash('sha256', $newReference),
            'old_fingerprint' => $oldFingerprint,
            'new_fingerprint' => $newFingerprint,
            'actor_id' => $context->actorId,
            'correlation_id' => $context->correlationId,
            'overlap_until' => $overlapUntil,
            'created_at' => $createdAt,
        ]);
    }
}
