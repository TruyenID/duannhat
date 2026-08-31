<?php

use App\Console\Commands\CheckSchemaDrift;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * #1216 review round 1 — the scratch database must never resolve to the live one.
 *
 * `schema:drift-check` runs `migrate:fresh --force` against a scratch
 * connection. Everything the command promises ("the live database is only ever
 * READ") rests on that connection pointing somewhere else. It did not always.
 *
 * The original build set `['database' => $scratch] + $liveConfig` and left the
 * live `url` in the inherited config. `config/database.php` carries
 * `'url' => env('DB_URL')`, and Laravel's ConfigurationUrlParser merges a URL's
 * components OVER the array config:
 *
 *     array_merge($config, $this->getPrimaryOptions($decoded), ...)
 *
 * so on any deployment that sets DB_URL — the exact deployment shape this
 * command exists to be pointed at — the scratch connection resolved to the LIVE
 * database and `migrate:fresh --force` would have emptied it. The name guard
 * did not fire: it compares the strings the caller typed, and
 * 'tempo_driftcheck' !== 'tempo'.
 *
 * These assert on the RESOLVED database name, because that is what
 * `migrate:fresh` acts on. A test that only checked the config array would have
 * passed against the broken version too.
 */
it('does not resolve the scratch connection to the live database when DB_URL is set', function () {
    Config::set('database.connections.drift_live_probe', [
        'driver' => 'mysql',
        'url' => 'mysql://someone:secret@db.internal:3306/tempo_prod',
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'tempo_prod',
        'username' => 'root',
        'password' => '',
    ]);

    Config::set(
        'database.connections.drift_scratch_probe',
        CheckSchemaDrift::scratchConnectionConfig(
            Config::get('database.connections.drift_live_probe'),
            'tempo_prod_driftcheck',
        ),
    );
    DB::purge('drift_scratch_probe');

    expect(DB::connection('drift_scratch_probe')->getDatabaseName())
        ->toBe('tempo_prod_driftcheck')
        ->not->toBe('tempo_prod');
});

it('keeps the credentials the URL carried instead of falling back to localhost', function () {
    // Dropping `url` outright would also have fixed the database name — and
    // broken every URL-only deployment, where host/user/password exist nowhere
    // else. Resolving the URL into concrete keys keeps them.
    Config::set('database.connections.drift_live_probe', [
        'driver' => 'mysql',
        'url' => 'mysql://someone:secret@db.internal:3307/tempo_prod',
        'database' => null,
    ]);

    $config = CheckSchemaDrift::scratchConnectionConfig(
        Config::get('database.connections.drift_live_probe'),
        'tempo_prod_driftcheck',
    );

    expect($config['host'])->toBe('db.internal')
        ->and($config['port'])->toBe(3307)
        ->and($config['username'])->toBe('someone')
        ->and($config['password'])->toBe('secret')
        ->and($config['database'])->toBe('tempo_prod_driftcheck')
        ->and($config)->not->toHaveKey('url');
});

it('still points at the scratch database when no URL is involved', function () {
    Config::set('database.connections.drift_live_probe', [
        'driver' => 'mysql',
        'url' => null,
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'tempo',
        'username' => 'root',
        'password' => '',
    ]);

    Config::set(
        'database.connections.drift_scratch_probe',
        CheckSchemaDrift::scratchConnectionConfig(
            Config::get('database.connections.drift_live_probe'),
            'tempo_driftcheck',
        ),
    );
    DB::purge('drift_scratch_probe');

    expect(DB::connection('drift_scratch_probe')->getDatabaseName())->toBe('tempo_driftcheck');
});
