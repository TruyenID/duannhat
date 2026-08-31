<?php

namespace App\Services\DomainMutation;

use LogicException;
use ReflectionClass;
use WeakMap;

/**
 * Process-local issuance capability for an exact, final, configured adapter class.
 *
 * Port implementation alone is insufficient. Configuration defaults to an empty
 * allowlist, so runtime issuance remains fail-closed until composition-root wiring.
 */
final class VerificationAuthority
{
    /** @var WeakMap<self, array{adapter: object, adapter_class: string, port: string, scopes: list<string>}>|null */
    private static ?WeakMap $grants = null;

    private function __construct() {}

    /** @param list<string> $scopes */
    public static function forConfiguredAdapter(object $adapter, string $port, array $scopes): self
    {
        if (! is_a($adapter, $port)) {
            throw new LogicException("Issuance adapter must implement {$port}.");
        }
        $reflection = new ReflectionClass($adapter);
        if ($reflection->isAnonymous() || ! $reflection->isFinal()) {
            throw new LogicException('Mutation issuance adapter must be a named final class.');
        }

        $scopes = array_values(array_unique(array_map(
            static fn (string $scope): string => MutationCommand::safeToken($scope, 'verificationScope', 160),
            $scopes,
        )));
        sort($scopes, SORT_STRING);
        if ($scopes === []) {
            throw new LogicException('Mutation issuance authority requires at least one scope.');
        }

        $configured = function_exists('config') ? config('domain_mutation.issuance_adapters', []) : [];
        foreach ($scopes as $scope) {
            if (($configured[$port][$scope] ?? null) !== $adapter::class) {
                throw new LogicException("Adapter is not configured as the exact issuance authority for {$scope}.");
            }
        }

        $authority = new self;
        self::$grants ??= new WeakMap;
        self::$grants[$authority] = [
            'adapter' => $adapter,
            'adapter_class' => $adapter::class,
            'port' => $port,
            'scopes' => $scopes,
        ];

        return $authority;
    }

    public function assertAuthorizes(object $adapter, string $port, string $scope): void
    {
        $grant = self::$grants[$this] ?? null;
        if ($grant === null
            || $grant['adapter'] !== $adapter
            || $grant['adapter_class'] !== $adapter::class
            || $grant['port'] !== $port
            || ! in_array($scope, $grant['scopes'], true)) {
            throw new LogicException("Issuance authority does not authorize {$scope}.");
        }
    }

    public function __serialize(): array
    {
        throw new LogicException('Verification authority cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return [];
    }
}
