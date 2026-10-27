<?php

namespace Zzktz\LaravelHealth\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Zzktz\LaravelHealth\LaravelHealthServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaravelHealthServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'health.service' => 'health-test',
            'health.token' => 'test-monitor-token',
            'health.timeout_ms' => 4000,
            'health.checks' => [
                'mysql' => [
                    ['name' => 'primary', 'connection' => 'mysql', 'required' => true],
                ],
                'redis' => [
                    ['name' => 'default', 'connection' => 'default', 'required' => true],
                ],
            ],
            'database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'database' => 'health_test',
                'username' => 'health_test',
                'password' => 'not-used-by-test',
            ],
            'database.redis.client' => 'phpredis',
            'database.redis.default' => ['host' => '127.0.0.1', 'port' => 6379],
        ]);
    }
}
