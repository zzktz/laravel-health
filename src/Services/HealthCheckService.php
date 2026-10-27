<?php

namespace Zzktz\LaravelHealth\Services;

use Throwable;

class HealthCheckService
{
    private const SCHEMA_VERSION = '1';
    private const ERROR_CODES = [
        'connection_failed',
        'timeout',
        'authentication_failed',
        'configuration_error',
        'unexpected_response',
    ];

    private HealthDependencyChecker $checker;

    public function __construct(HealthDependencyChecker $checker)
    {
        $this->checker = $checker;
    }

    public function live(): array
    {
        $startedAt = microtime(true);
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'alive',
            'service' => $this->serviceName(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => $this->durationMs($startedAt),
            'checks' => [
                'app' => [
                    'status' => 'ok',
                    'required' => true,
                    'duration_ms' => 0,
                ],
            ],
        ];
    }

    public function ready(): array
    {
        $startedAt = microtime(true);
        $configurationError = $this->configurationError();
        $checks = [
            'app' => [
                'status' => 'ok',
                'required' => true,
                'duration_ms' => 0,
            ],
        ];

        if ($configurationError !== null) {
            $checks['configuration'] = [
                'status' => 'fail',
                'required' => true,
                'duration_ms' => 0,
                'error' => 'configuration_error',
            ];

            return $this->response('unhealthy', $checks, $startedAt);
        }

        $definitions = $this->definitions();
        $timeoutMs = (int) config('health.timeout_ms');
        $deadline = $startedAt + ($timeoutMs / 1000);
        $requiredFailure = false;
        $optionalFailure = false;

        foreach ($definitions as $index => $definition) {
            $checkName = $definition['type'] . '.' . $definition['name'];
            $required = $definition['required'];
            $remainingMs = (int) floor(($deadline - microtime(true)) * 1000);

            if ($remainingMs <= 0) {
                $checks[$checkName] = [
                    'status' => 'skipped',
                    'required' => $required,
                    'duration_ms' => 0,
                ];
                $requiredFailure = $requiredFailure || $required;
                $optionalFailure = $optionalFailure || !$required;
                continue;
            }

            $remainingChecks = count($definitions) - $index;
            $allocationMs = max(1, (int) floor($remainingMs / max(1, $remainingChecks)));
            $checkStartedAt = microtime(true);

            try {
                if ($definition['type'] === 'mysql') {
                    $this->checker->mysql($definition['connection'], $allocationMs);
                } else {
                    $this->checker->redis($definition['connection'], $allocationMs);
                }
                $checks[$checkName] = [
                    'status' => 'ok',
                    'required' => $required,
                    'duration_ms' => $this->durationMs($checkStartedAt),
                ];
            } catch (Throwable $exception) {
                $errorCode = $exception instanceof HealthCheckException
                    ? $exception->errorCode()
                    : 'connection_failed';
                if (!in_array($errorCode, self::ERROR_CODES, true)) {
                    $errorCode = 'connection_failed';
                }
                $checks[$checkName] = [
                    'status' => 'fail',
                    'required' => $required,
                    'duration_ms' => $this->durationMs($checkStartedAt),
                    'error' => $errorCode,
                ];
                $requiredFailure = $requiredFailure || $required;
                $optionalFailure = $optionalFailure || !$required;
            }
        }

        $status = $requiredFailure ? 'unhealthy' : ($optionalFailure ? 'degraded' : 'healthy');
        return $this->response($status, $checks, $startedAt);
    }

    private function configurationError(): ?string
    {
        $service = config('health.service');
        if (!is_string($service) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $service)) {
            return 'service';
        }

        $timeoutMs = config('health.timeout_ms');
        if (!is_int($timeoutMs) || $timeoutMs < 100 || $timeoutMs > 10000) {
            return 'timeout';
        }

        $checks = config('health.checks');
        if (!is_array($checks) || !isset($checks['mysql'], $checks['redis'])) {
            return 'checks';
        }
        if (!is_array($checks['mysql']) || !is_array($checks['redis'])) {
            return 'checks';
        }

        foreach (['mysql', 'redis'] as $type) {
            if (!is_array($checks[$type])) {
                return 'checks';
            }
            $names = [];
            foreach ($checks[$type] as $definition) {
                if (!is_array($definition)
                    || !isset($definition['name'], $definition['connection'])
                    || !is_string($definition['name'])
                    || !preg_match('/^[A-Za-z0-9._-]{1,50}$/', $definition['name'])
                    || !is_string($definition['connection'])
                    || $definition['connection'] === ''
                    || !array_key_exists('required', $definition)
                    || !is_bool($definition['required'])
                    || in_array($definition['name'], $names, true)) {
                    return 'checks';
                }
                $names[] = $definition['name'];

                if ($type === 'mysql') {
                    $connectionConfig = config('database.connections.' . $definition['connection']);
                    if (!is_array($connectionConfig) || ($connectionConfig['driver'] ?? null) !== 'mysql') {
                        return 'checks';
                    }
                } elseif (!is_array(config('database.redis.' . $definition['connection']))) {
                    return 'checks';
                }
            }
        }

        $redisClient = config('database.redis.client');
        if (count($checks['redis']) > 0 && !in_array($redisClient, ['phpredis', 'predis'], true)) {
            return 'redis_client';
        }

        return null;
    }

    private function definitions(): array
    {
        $definitions = [];
        foreach (['mysql', 'redis'] as $type) {
            foreach (config('health.checks.' . $type, []) as $definition) {
                $definitions[] = array_merge($definition, ['type' => $type]);
            }
        }

        return $definitions;
    }

    private function response(string $status, array $checks, float $startedAt): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'service' => $this->serviceName(),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_ms' => $this->durationMs($startedAt),
            'checks' => $checks,
        ];
    }

    private function serviceName(): string
    {
        $service = config('health.service');
        return is_string($service) && preg_match('/^[A-Za-z0-9._-]{1,100}$/', $service)
            ? $service
            : 'unknown-service';
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
