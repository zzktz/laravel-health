<?php

namespace Zzktz\LaravelHealth\Tests\Unit;

use Zzktz\LaravelHealth\Services\HealthCheckException;
use Zzktz\LaravelHealth\Services\HealthDependencyChecker;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\RedisManager;
use Mockery;
use PDO;
use Zzktz\LaravelHealth\Tests\TestCase;

class HealthDependencyCheckerTest extends TestCase
{
    public function testMysqlCheckUsesOnlySelectOneAndDisconnects(): void
    {
        config(['database.connections.health_test' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'health_test',
            'username' => 'health_test',
            'password' => 'not-used-by-test',
            'options' => [],
        ]]);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('select')->once()->with('SELECT 1')->andReturn([]);
        $connection->shouldReceive('disconnect')->once();

        $factory = Mockery::mock(ConnectionFactory::class);
        $factory->shouldReceive('make')->once()->withArgs(function ($config, $name) {
            return $name === 'health_test'
                && $config['options'][PDO::ATTR_TIMEOUT] === 1;
        })->andReturn($connection);

        (new HealthDependencyChecker($factory))->mysql('health_test', 500);
    }

    public function testRedisCheckUsesPingWithShortTimeoutAndDisconnects(): void
    {
        config([
            'database.redis.client' => 'phpredis',
            'database.redis.default' => [
                'host' => '127.0.0.1',
                'port' => 6379,
                'database' => 0,
                'timeout' => 10,
                'read_timeout' => 10,
            ],
        ]);

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('ping')->once()->andReturn('PONG');
        $connection->shouldReceive('disconnect')->once();

        $manager = Mockery::mock(RedisManager::class);
        $manager->shouldReceive('connection')->once()->with('health_probe')->andReturn($connection);

        $factory = Mockery::mock(ConnectionFactory::class);
        $checker = new class($factory, $manager) extends HealthDependencyChecker {
            public array $probeConfig = [];

            private RedisManager $manager;

            public function __construct(ConnectionFactory $factory, RedisManager $manager)
            {
                parent::__construct($factory);
                $this->manager = $manager;
            }

            protected function createRedisManager(string $client, array $redisConfig): RedisManager
            {
                $this->probeConfig = $redisConfig;
                return $this->manager;
            }
        };

        $checker->redis('default', 250);

        $this->assertLessThanOrEqual(0.25, $checker->probeConfig['health_probe']['timeout']);
        $this->assertLessThanOrEqual(0.25, $checker->probeConfig['health_probe']['read_timeout']);
    }

    public function testRedisUnexpectedPingResponseIsReportedWithoutDetails(): void
    {
        config([
            'database.redis.client' => 'phpredis',
            'database.redis.default' => ['host' => '127.0.0.1', 'port' => 6379],
        ]);

        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('ping')->once()->andReturn(false);
        $connection->shouldReceive('disconnect')->once();

        $manager = Mockery::mock(RedisManager::class);
        $manager->shouldReceive('connection')->once()->with('health_probe')->andReturn($connection);
        $factory = Mockery::mock(ConnectionFactory::class);
        $checker = new class($factory, $manager) extends HealthDependencyChecker {
            private RedisManager $manager;

            public function __construct(ConnectionFactory $factory, RedisManager $manager)
            {
                parent::__construct($factory);
                $this->manager = $manager;
            }

            protected function createRedisManager(string $client, array $redisConfig): RedisManager
            {
                return $this->manager;
            }
        };

        try {
            $checker->redis('default', 250);
            $this->fail('应拒绝非 PONG 响应。');
        } catch (HealthCheckException $exception) {
            $this->assertSame('unexpected_response', $exception->errorCode());
            $this->assertStringNotContainsString('127.0.0.1', $exception->getMessage());
        }
    }
}
