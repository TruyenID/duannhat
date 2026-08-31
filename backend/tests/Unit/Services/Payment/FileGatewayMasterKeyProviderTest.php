<?php

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\Secret\Exceptions\InvalidGatewaySecretConfiguration;
use App\Services\Payment\Secret\FileGatewayMasterKeyProvider;
use LogicException;
use PHPUnit\Framework\TestCase;

final class FileGatewayMasterKeyProviderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_link($path) || is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_loads_exact_versioned_keys_without_exposing_material(): void
    {
        $path = $this->keyring([
            'active_key_id' => 'key-b',
            'keys' => [
                'key-a' => 'base64:'.base64_encode(str_repeat('A', 32)),
                'key-b' => 'base64:'.base64_encode(str_repeat('B', 32)),
            ],
        ]);
        $provider = new FileGatewayMasterKeyProvider($path, []);

        $active = $provider->active();
        $old = $provider->byId('key-a');
        self::assertSame('key-b', $active->keyId);
        self::assertSame(hash('sha256', str_repeat('B', 32)), hash('sha256', $active->bytes()));
        self::assertSame(hash('sha256', str_repeat('A', 32)), hash('sha256', $old->bytes()));
        self::assertStringNotContainsString(str_repeat('B', 32), print_r($active, true).var_export($active, true));

        $this->expectException(LogicException::class);
        serialize($active);
    }

    public function test_rejects_missing_relative_symlink_permissive_forbidden_and_malformed_keyrings(): void
    {
        $valid = ['active_key_id' => 'key-a', 'keys' => ['key-a' => 'base64:'.base64_encode(str_repeat('A', 32))]];
        $permissive = $this->keyring($valid, 0644);
        $malformed = $this->rawKeyring('{not-json');
        $shortKey = $this->keyring(['active_key_id' => 'key-a', 'keys' => ['key-a' => 'base64:'.base64_encode('short')]]);
        $symlinkTarget = $this->keyring($valid);
        $symlink = $symlinkTarget.'.link';
        symlink($symlinkTarget, $symlink);
        $this->paths[] = $symlink;

        $cases = [
            [null, []],
            ['relative-keyring.json', []],
            [$permissive, []],
            [$malformed, []],
            [$shortKey, []],
            [$symlink, []],
            [$symlinkTarget, [dirname($symlinkTarget)]],
        ];

        foreach ($cases as [$path, $forbiddenRoots]) {
            try {
                (new FileGatewayMasterKeyProvider($path, $forbiddenRoots))->active();
                self::fail('Unsafe keyring configuration must fail closed.');
            } catch (InvalidGatewaySecretConfiguration $error) {
                self::assertSame('PAYMENT_SECRET_STORE_CONFIGURATION_INVALID', $error->errorCode);
                if (is_string($path) && $path !== '') {
                    self::assertStringNotContainsString($path, $error->getMessage());
                }
                self::assertStringNotContainsString(str_repeat('A', 32), $error->getMessage());
            }
        }
    }

    public function test_rejects_unknown_key_id_without_disclosing_the_keyring(): void
    {
        $path = $this->keyring([
            'active_key_id' => 'key-a',
            'keys' => ['key-a' => 'base64:'.base64_encode(str_repeat('A', 32))],
        ]);

        try {
            (new FileGatewayMasterKeyProvider($path, []))->byId('missing-key');
            self::fail('Unknown master key must fail closed.');
        } catch (InvalidGatewaySecretConfiguration $error) {
            self::assertSame('key_missing', $error->reason);
            self::assertStringNotContainsString($path, $error->getMessage());
            self::assertStringNotContainsString('missing-key', $error->getMessage());
        }
    }

    /** @param array<string, mixed> $contents */
    private function keyring(array $contents, int $mode = 0600): string
    {
        return $this->rawKeyring(json_encode($contents, JSON_THROW_ON_ERROR), $mode);
    }

    private function rawKeyring(string $contents, int $mode = 0600): string
    {
        $path = realpath(sys_get_temp_dir()).'/tempo-master-key-'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($path, $contents);
        chmod($path, $mode);
        $this->paths[] = $path;

        return $path;
    }
}
