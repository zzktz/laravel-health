<?php

namespace Zzktz\LaravelHealth\Services;

use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\ConfigurationUrlParser;
use PDO;
use Throwable;

class HealthDependencyChecker
{
    private ConnectionFactory $databaseFactory;

    public function __construct(ConnectionFactory $databaseFactory)
    {
        $this->databaseFactory = $databaseFactory;
    }

    public function mysql(string $connectionName, int $timeoutMs): void
    {
        $config = config('database.connections.' . $connectionName);
        if (!extension_loaded('pdo_mysql')
            || !is_array($config)
            || ($config['driver'] ?? null) !== 'mysql') {
            throw new HealthCheckException('configuration_error');
        }

        try {
            $config = (new ConfigurationUrlParser())->parseConfiguration($config);
            $timeoutSeconds = max(1, min(2, (int) ceil($timeoutMs / 1000)));
            $options = $config['options'] ?? [];
            $options[PDO::ATTR_TIMEOUT] = $timeoutSeconds;
            if (defined('PDO::MYSQL_ATTR_READ_TIMEOUT')) {
                $options[constant('PDO::MYSQL_ATTR_READ_TIMEOUT')] = $timeoutSeconds;
            }
            $config['options'] = $options;

            $connection = $this->databaseFactory->make($config, $connectionName);
            try {
                $connection->select('SELECT 1');
            } finally {
                $connection->disconnect();
            }
        } catch (HealthCheckException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new HealthCheckException($this->errorCode($exception), $exception);
        }
    }

    public function redis(string $connectionName, int $timeoutMs): void
    {
        $redisConfig = config('database.redis', []);
        $client = $redisConfig['client'] ?? null;
        $connectionConfig = $redisConfig[$connectionName] ?? null;
        $clientAvailable = $client === 'phpredis'
            ? extension_loaded('redis')
            : ($client === 'predis' && class_exists('Predis\\Client'));
        if (!$clientAvailable || !is_array($connectionConfig)) {
            throw new HealthCheckException('configuration_error');
        }

        try {
            $timeoutSeconds = max(0.1, min(1.0, $timeoutMs / 1000));
            $connectionConfig = (new ConfigurationUrlParser())->parseConfiguration($connectionConfig);
            $connectionConfig['timeout'] = $timeoutSeconds;
            $connectionConfig['read_timeout'] = $timeoutSeconds;
            $probeName = 'health_probe';
            $redisConfig[$probeName] = $connectionConfig;

            $manager = $this->createRedisManager($client, $redisConfig);
            $connection = $manager->connection($probeName);
            try {
                $response = $connection->ping();
                if (!$this->isPong($response)) {
                    throw new HealthCheckException('unexpected_response');
                }
            } finally {
                $this->disconnectRedis($connection);
            }
        } catch (HealthCheckException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new HealthCheckException($this->errorCode($exception), $exception);
        }
    }

    private function isPong($response): bool
    {
        if ($response === true) {
            return true;
        }
        if (!is_string($response) && !is_object($response)) {
            return false;
        }

        return strtoupper(trim((string) $response, "+ \t\n\r\0\x0B")) === 'PONG';
    }

    protected function createRedisManager(string $client, array $redisConfig): RedisManager
    {
        return new RedisManager(app(), $client, $redisConfig);
    }

    private function disconnectRedis($connection): void
    {
        if (method_exists($connection, 'disconnect')) {
            $connection->disconnect();
            return;
        }

        $client = $connection->client();
        if (method_exists($client, 'disconnect')) {
            $client->disconnect();
            return;
        }

        if (method_exists($client, 'getConnection')) {
            $clientConnection = $client->getConnection();
            if (method_exists($clientConnection, 'disconnect')) {
                $clientConnection->disconnect();
            }
        }
    }

    private function errorCode(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());
        if (preg_match('/noauth|wrongpass|authentication|access denied|sqlstate\[28000\]/', $message)) {
            return 'authentication_failed';
        }
        if (preg_match('/timed? ?out|timeout|hyt00|operation timed/', $message)) {
            return 'timeout';
        }

        return 'connection_failed';
    }
}
