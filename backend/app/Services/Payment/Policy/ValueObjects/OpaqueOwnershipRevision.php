<?php

namespace App\Services\Payment\Policy\ValueObjects;

use InvalidArgumentException;

final class OpaqueOwnershipRevision
{
    public static function validate(string $value): string
    {
        if ($value === '' || strlen($value) > 32) {
            throw new InvalidArgumentException('ownershipRevision must contain between 1 and 32 bytes.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('ownershipRevision cannot contain control characters.');
        }

        return $value;
    }
}
