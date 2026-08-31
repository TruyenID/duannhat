<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        foreach ([
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => 'bootstrap/cache/config-testing.php',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($connection === 'sqlite' && $database === ':memory:') {
            return $app;
        }

        if (preg_match('/(?:^|[_-])tests?(?:ing)?$/i', $database) === 1) {
            return $app;
        }

        throw new RuntimeException(
            "Unsafe test database [{$connection}:{$database}]. ".
            'Tests may only use SQLite :memory: or a database ending in test/testing.',
        );
    }
}
