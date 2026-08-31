<?php

declare(strict_types=1);

namespace Database\Seeders;

use RuntimeException;

/**
 * Marks a seeder that fabricates development data — mock console tenants, demo
 * orders, cashier shifts, device pairing codes, sample floor plans.
 *
 * DatabaseSeeder already keeps these out of the production path, but that is
 * only half the exposure: `php artisan db:seed --class=MockDataSeeder --force`
 * is one shell history entry away from a real shop's database, and several of
 * these seeders write with updateOrCreate — the damage would not announce
 * itself. So refuse outright instead of trusting the caller.
 *
 * Seeders that legitimately run in production (master data, catalog snapshot,
 * notification templates) must NOT use this trait.
 */
trait RefusesToRunInProduction
{
    protected function guardAgainstProduction(): void
    {
        if (! app()->isProduction()) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s seeds development-only data and must never run against production.',
            static::class,
        ));
    }
}
