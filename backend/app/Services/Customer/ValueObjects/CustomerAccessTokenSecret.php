<?php

namespace App\Services\Customer\ValueObjects;

use LogicException;
use SensitiveParameter;
use WeakMap;

final class CustomerAccessTokenSecret
{
    /** @var WeakMap<self, string>|null */
    private static ?WeakMap $values = null;

    public function __construct(#[SensitiveParameter] string $token)
    {
        if ($token === '') {
            throw new \InvalidArgumentException('Access token cannot be empty.');
        }
        self::$values ??= new WeakMap;
        self::$values[$this] = $token;
    }

    public function reveal(): string
    {
        return self::$values[$this] ?? throw new LogicException('Access token is unavailable.');
    }

    public function __serialize(): array
    {
        throw new LogicException('Access tokens cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return [];
    }
}
