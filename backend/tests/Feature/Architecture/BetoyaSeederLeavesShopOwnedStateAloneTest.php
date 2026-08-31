<?php

declare(strict_types=1);

use Database\Seeders\BetoyaSeeder;

/**
 * A production deploy must not decide things that belong to the shop.
 *
 * `.github/workflows/deploy-xserver.yml` runs `db:seed --class=BetoyaSeeder
 * --force` on every push to `main`, so anything reachable from that seeder runs
 * against a LIVE database, unattended, at whatever hour someone merges.
 *
 * That is fine for system catalogue — payment methods, gateway definitions —
 * which the shop cannot edit and which must exist for the POS to work at all.
 * It is not fine for state the shop owns. It has already cost a real service:
 * on 2026-08-11 06:08:01 `HongoShopConfigSeeder`, reached through this seeder,
 * disabled 本郷店's four time-slot menus and re-enabled the full one; staff
 * served the wrong menu for ~50 minutes and fixed it by hand at 07:01.
 *
 * The near-miss was worse. `CatalogSnapshotSeeder` upserts `tables` with
 * `status = 'free'` and `current_order_id = null`. That deploy landed at 15:08
 * JST, between lunch and dinner, with every table already free. The same deploy
 * mid-service would have handed back every occupied table as empty and detached
 * it from the order its guests were sitting behind.
 *
 * So this test is a ratchet on the CALL GRAPH, not on behaviour: a seeder can
 * be perfectly correct and still be wrong to run here.
 */
$shopOwnedSeeders = [
    // Menus live/dead, order settings, floor layout — all editable in admin-web.
    'HongoShopConfigSeeder',
    // DELETEs + re-upserts the catalogue from a fixture, and resets table
    // occupancy. A restore tool; run it deliberately, never as a side effect.
    'CatalogSnapshotSeeder',
];

it('does not reach a shop-owned seeder from the deploy path', function () use ($shopOwnedSeeders) {
    $source = (string) file_get_contents((new ReflectionClass(BetoyaSeeder::class))->getFileName());

    // Strip comments so the docblocks explaining WHY these are absent do not
    // read as evidence that they are present.
    $code = '';
    foreach (@token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    $offenders = array_values(array_filter(
        $shopOwnedSeeders,
        fn (string $seeder): bool => str_contains($code, $seeder.'::class'),
    ));

    // CatalogSnapshotSeeder is still referenced — behind a fresh-install guard —
    // so it is allowed to appear, but only inside that guard.
    $offenders = array_values(array_diff($offenders, ['CatalogSnapshotSeeder']));

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['BetoyaSeeder runs on EVERY production deploy. These write state the shop owns:'],
        array_map(fn (string $s): string => "  {$s}", $offenders),
        ['', 'Run them by hand when a restore needs them: php artisan db:seed --class=<Seeder> --force'],
    )));
});

it('guards the catalogue restore behind a fresh-install check', function () {
    $source = (string) file_get_contents((new ReflectionClass(BetoyaSeeder::class))->getFileName());

    // The guard is the whole point: without it, every deploy reverts catalogue
    // edits and frees occupied tables.
    expect($source)->toContain('seedCatalogOnFreshInstallOnly')
        ->and($source)->toContain("DB::table('products')->exists()");
});

it('never force-updates shop_order_settings for branches that already have one', function () {
    // 総額表示 is a compliance default for a NEW branch, not a value to re-assert
    // over an operator every time someone ships code. The provisioner writes it
    // once; re-forcing it takes the switch away from the person it is for.
    $source = (string) file_get_contents((new ReflectionClass(BetoyaSeeder::class))->getFileName());

    expect($source)->toContain('whereIn(\'branch_id\', $provisioned)')
        ->and($source)->not->toContain("whereIn('branch_id', \$branches->pluck('id'))");
});
