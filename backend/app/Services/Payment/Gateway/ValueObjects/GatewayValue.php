<?php

namespace App\Services\Payment\Gateway\ValueObjects;

use InvalidArgumentException;

abstract readonly class GatewayValue
{
    protected static function nonEmpty(string $value, string $name, int $maxLength = 255): string
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException("{$name} must contain between 1 and {$maxLength} characters.");
        }

        return $value;
    }

    protected static function uuid(string $value, string $name): string
    {
        $value = strtolower(trim($value));

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value) !== 1) {
            throw new InvalidArgumentException("{$name} must be a valid UUID.");
        }

        return $value;
    }

    /**
     * #1211 — an identifier issued by the CONSOLE, not by us.
     *
     * The console addresses org units with ULIDs (every seeder writes
     * `console_branch_id => Str::ulid()`), so validating one as an RFC-4122
     * UUID rejected every real branch and turned
     * GET /shops/{slug}/payment-configuration into a 500 — all four tabs of the
     * shop payment screen share that call. The suite never saw it because
     * BranchFactory writes a UUID there while the seeders write a ULID.
     *
     * We cannot make format promises about another system's identifiers, so
     * this accepts any opaque key. Identifiers WE own (organizationId, brandId,
     * branchId) stay on the strict uuid() check — that is the tenancy guard and
     * it is untouched.
     */
    protected static function externalId(string $value, string $name): string
    {
        return self::requestKey(strtolower(trim($value)), $name);
    }

    protected static function requestKey(string $value, string $name): string
    {
        $value = self::nonEmpty($value, $name, 255);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $value) !== 1) {
            throw new InvalidArgumentException("{$name} contains unsupported characters.");
        }

        return $value;
    }
}
