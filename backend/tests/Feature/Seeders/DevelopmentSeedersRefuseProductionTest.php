<?php

declare(strict_types=1);

use Database\Seeders\CustomerOrderSeeder;
use Database\Seeders\DashboardSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDevSeeder;
use Database\Seeders\MockDataSeeder;
use Database\Seeders\OrderSnapshotSeeder;
use Database\Seeders\Plan022ComprehensiveDemoSeeder;
use Database\Seeders\Plan036TillTrackingDemoSeeder;
use Database\Seeders\PosE2eSeeder;
use Database\Seeders\PosShiftSeedSeeder;
use Database\Seeders\ShopManagerUserSeeder;

/**
 * These seeders fabricate a mock console tenant, demo orders, cashier shifts and
 * device pairing codes. DatabaseSeeder keeps them off the production path, but
 * `db:seed --class=...` bypasses DatabaseSeeder entirely — so each one refuses
 * on its own.
 */
it('refuses to seed development data into a production database', function (string $seeder) {
    app()->detectEnvironment(fn (): string => 'production');

    // Invoked directly, not via $this->seed() — the artisan command stops at
    // Laravel's own production confirmation, which `--force` waives. The guard
    // under test is the one that survives --force.
    expect(app()->isProduction())->toBeTrue()
        ->and(fn () => app()->call([app($seeder), 'run']))
        ->toThrow(RuntimeException::class, 'must never run against production');
})->with([
    MockDataSeeder::class,
    LocalDevSeeder::class,
    ShopManagerUserSeeder::class,
    DashboardSeeder::class,
    CustomerOrderSeeder::class,
    // #2220 — upsert thẳng vào users/tables/customer_orders/order_payments từ
    // một ảnh chụp; chạy nhầm trên production là đè chứng từ thật bằng demo.
    OrderSnapshotSeeder::class,
    Plan022ComprehensiveDemoSeeder::class,
    Plan036TillTrackingDemoSeeder::class,
    PosE2eSeeder::class,
    PosShiftSeedSeeder::class,
]);

/**
 * The production branch is what a deploy actually executes. Anything reachable
 * from it must be safe there, so no guarded seeder may appear in that list —
 * adding one would turn every deploy into a hard failure.
 */
it('never routes a development-only seeder through the production branch', function () {
    $source = file_get_contents((new ReflectionClass(DatabaseSeeder::class))->getFileName());
    $productionBranch = substr($source, (int) strpos($source, 'isProduction()'));
    $productionBranch = substr($productionBranch, 0, (int) strpos($productionBranch, 'return;'));

    $guarded = collect(glob(database_path('seeders/*.php')))
        ->filter(fn (string $path): bool => str_contains(
            (string) file_get_contents($path),
            'use RefusesToRunInProduction;',
        ))
        ->map(fn (string $path): string => basename($path, '.php'))
        ->values();

    expect($guarded)->not->toBeEmpty()
        ->and($guarded->filter(fn (string $seeder): bool => str_contains($productionBranch, $seeder.'::class'))->all())
        ->toBeEmpty();
});
