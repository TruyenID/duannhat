<?php

namespace App\Services\Payment\Secret;

use App\Services\Payment\Secret\Contracts\GatewayMasterKeyProvider;
use App\Services\Payment\Secret\Exceptions\InvalidGatewaySecretConfiguration;
use App\Services\Payment\Secret\ValueObjects\GatewayMasterKey;
use JsonException;

final class FileGatewayMasterKeyProvider implements GatewayMasterKeyProvider
{
    /** @param list<string> $forbiddenRoots */
    public function __construct(
        private readonly ?string $path,
        private readonly array $forbiddenRoots,
    ) {}

    public function active(): GatewayMasterKey
    {
        $keyring = $this->keyring();

        return $this->decode($keyring['active_key_id'], $keyring['keys'][$keyring['active_key_id']] ?? null);
    }

    public function byId(string $keyId): GatewayMasterKey
    {
        $keyring = $this->keyring();

        return $this->decode($keyId, $keyring['keys'][$keyId] ?? null);
    }

    /** @return array{active_key_id: string, keys: array<string, string>} */
    private function keyring(): array
    {
        if ($this->path === null || $this->path === '' || ! str_starts_with($this->path, DIRECTORY_SEPARATOR)) {
            throw new InvalidGatewaySecretConfiguration('keyring_path');
        }

        if (is_link($this->path) || ! is_file($this->path) || ! is_readable($this->path)) {
            throw new InvalidGatewaySecretConfiguration('keyring_file');
        }

        $realPath = realpath($this->path);
        if ($realPath === false || $realPath !== $this->path) {
            throw new InvalidGatewaySecretConfiguration('keyring_canonical_path');
        }

        foreach ($this->forbiddenRoots as $root) {
            $realRoot = realpath($root);
            if ($realRoot !== false && ($realPath === $realRoot || str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR))) {
                throw new InvalidGatewaySecretConfiguration('keyring_location');
            }
        }

        $permissions = fileperms($realPath);
        if ($permissions === false || ($permissions & 0077) !== 0 || ($permissions & 0600) === 0) {
            throw new InvalidGatewaySecretConfiguration('keyring_permissions');
        }

        if (function_exists('posix_geteuid') && fileowner($realPath) !== posix_geteuid()) {
            throw new InvalidGatewaySecretConfiguration('keyring_owner');
        }

        $contents = file_get_contents($realPath);
        if ($contents === false || strlen($contents) > 32_768) {
            throw new InvalidGatewaySecretConfiguration('keyring_size');
        }

        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidGatewaySecretConfiguration('keyring_json');
        }

        if (! is_array($decoded)
            || ! is_string($decoded['active_key_id'] ?? null)
            || ! is_array($decoded['keys'] ?? null)
            || array_keys($decoded) !== ['active_key_id', 'keys']) {
            throw new InvalidGatewaySecretConfiguration('keyring_shape');
        }

        foreach ($decoded['keys'] as $keyId => $encoded) {
            if (! is_string($keyId) || ! is_string($encoded)) {
                throw new InvalidGatewaySecretConfiguration('keyring_entry');
            }
        }

        /** @var array{active_key_id: string, keys: array<string, string>} $decoded */
        return $decoded;
    }

    private function decode(string $keyId, mixed $encoded): GatewayMasterKey
    {
        if (! is_string($encoded) || ! str_starts_with($encoded, 'base64:')) {
            throw new InvalidGatewaySecretConfiguration('key_missing');
        }

        $bytes = base64_decode(substr($encoded, 7), true);
        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new InvalidGatewaySecretConfiguration('key_material');
        }

        try {
            return new GatewayMasterKey($keyId, $bytes);
        } catch (\InvalidArgumentException) {
            throw new InvalidGatewaySecretConfiguration('key_id');
        }
    }
}
