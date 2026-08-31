<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JsonException;

/**
 * Create the gateway secret keyring that "Xác minh kết nối" and "Luân chuyển
 * thông tin xác thực" need to work at all.
 *
 * Nothing created this file before. `FileGatewayMasterKeyProvider` validates it
 * to the byte — absolute path, regular file, mode 0600, owned by the PHP user,
 * outside the repo and public root — but there was no supported way to produce
 * one, so both buttons on the HQ connection screen failed on every environment
 * that had never hand-rolled it: rotate 500 `InvalidGatewaySecretConfiguration`,
 * validate 503 `PAYMENT_SECRET_RESOLUTION_FAILED` (#F8).
 *
 * The key is independent of APP_KEY on purpose. Losing this file makes every
 * stored gateway secret undecryptable, so it is generated once and backed up
 * like a credential, not regenerated on deploy.
 */
class GenerateGatewayKeyring extends Command
{
    protected $signature = 'payments:generate-gateway-keyring
        {--path= : Absolute keyring path (defaults to config payments.secret_store.keyring_path)}
        {--key-id=k1 : Identifier recorded for the generated key}
        {--rotate : Add a new active key to an existing keyring, keeping the old ones}
        {--force : Overwrite an existing keyring — DESTROYS access to every secret encrypted with it}';

    protected $description = 'Generate the server-only payment gateway master keyring (plan-047 secret store)';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: config('payments.secret_store.keyring_path'));

        if ($path === '') {
            $this->components->error('No keyring path. Pass --path=/absolute/path or set PAYMENT_GATEWAY_KEYRING_PATH.');

            return self::FAILURE;
        }

        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $this->components->error("Keyring path must be absolute: {$path}");

            return self::FAILURE;
        }

        foreach ([base_path(), public_path()] as $forbidden) {
            $realForbidden = realpath($forbidden);
            if ($realForbidden !== false && str_starts_with($path, $realForbidden.DIRECTORY_SEPARATOR)) {
                $this->components->error("Refusing to write a keyring inside {$realForbidden} — it must live outside the repository and web root.");

                return self::FAILURE;
            }
        }

        $keyId = (string) $this->option('key-id');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $keyId) !== 1) {
            $this->components->error("Invalid --key-id: {$keyId}");

            return self::FAILURE;
        }

        $exists = is_file($path);
        $rotating = (bool) $this->option('rotate');

        if ($exists && ! $rotating && ! $this->option('force')) {
            $this->components->error("Keyring already exists at {$path}. Use --rotate to add a key, or --force to replace it (this destroys access to every secret encrypted with the current keys).");

            return self::FAILURE;
        }

        $keys = [];
        if ($exists && $rotating) {
            $existing = $this->readExisting($path);
            if ($existing === null) {
                return self::FAILURE;
            }
            $keys = $existing['keys'];

            if (array_key_exists($keyId, $keys)) {
                $this->components->error("Key id '{$keyId}' already exists in the keyring. Choose another --key-id.");

                return self::FAILURE;
            }
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->components->error("Could not create keyring directory {$directory}.");

            return self::FAILURE;
        }

        $keys[$keyId] = 'base64:'.base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));

        // Create with 0600 BEFORE any key material is written — writing first and
        // chmod'ing after leaves the key world-readable for the width of that gap.
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->components->error("Could not open {$path} for writing.");

            return self::FAILURE;
        }
        chmod($path, 0600);

        try {
            $payload = json_encode(
                ['active_key_id' => $keyId, 'keys' => $keys],
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
            );
        } catch (JsonException $exception) {
            fclose($handle);
            $this->components->error('Could not encode keyring: '.$exception->getMessage());

            return self::FAILURE;
        }

        fwrite($handle, $payload.PHP_EOL);
        fclose($handle);
        chmod($path, 0600);

        $this->components->info(($exists && $rotating ? 'Rotated' : 'Generated').' gateway keyring at '.$path);
        $this->components->twoColumnDetail('active_key_id', $keyId);
        $this->components->twoColumnDetail('keys', (string) count($keys));

        if ((string) config('payments.secret_store.keyring_path') !== $path) {
            $this->components->warn("Set PAYMENT_GATEWAY_KEYRING_PATH={$path} so the app reads this file.");
        }

        return self::SUCCESS;
    }

    /**
     * @return array{active_key_id: string, keys: array<string, string>}|null
     */
    private function readExisting(string $path): ?array
    {
        $contents = is_readable($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            $this->components->error("Existing keyring at {$path} is not readable.");

            return null;
        }

        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->components->error("Existing keyring at {$path} is not valid JSON.");

            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded['active_key_id'] ?? null) || ! is_array($decoded['keys'] ?? null)) {
            $this->components->error("Existing keyring at {$path} does not have the expected shape.");

            return null;
        }

        /** @var array{active_key_id: string, keys: array<string, string>} $decoded */
        return $decoded;
    }
}
