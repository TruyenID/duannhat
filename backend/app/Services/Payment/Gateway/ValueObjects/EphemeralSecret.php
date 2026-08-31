<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use LogicException;
use SensitiveParameter;
use WeakMap;

/**
 * Process-local secret carrier. Secret bytes live outside the object instance so normalizers,
 * var_export, debug output, and serialization cannot discover them as object properties.
 */
final class EphemeralSecret
{
    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    public function __construct(#[SensitiveParameter] string $value)
    {
        self::$values ??= new WeakMap;
        self::$values[$this] = $value;
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? throw new LogicException('Ephemeral secret is no longer available.');
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Ephemeral secrets cannot be serialized.');
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }
}
