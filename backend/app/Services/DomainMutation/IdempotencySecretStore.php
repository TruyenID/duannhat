<?php

namespace App\Services\DomainMutation;

use WeakMap;

/** @internal Keeps request secrets out of serializable/debuggable command state. */
final class IdempotencySecretStore
{
    /** @var WeakMap<MutationContext, string>|null */
    private static ?WeakMap $secrets = null;

    private function __construct() {}

    public static function put(MutationContext $context, string $secret): void
    {
        self::$secrets ??= new WeakMap;
        self::$secrets[$context] = $secret;
    }

    public static function reveal(MutationContext $context): string
    {
        return self::$secrets[$context] ?? throw new \LogicException('Idempotency secret is no longer available.');
    }
}
