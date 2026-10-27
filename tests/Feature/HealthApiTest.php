<?php

namespace Zzktz\LaravelHealth\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Mockery;
use RuntimeException;
use Zzktz\LaravelHealth\Services\HealthCheckException;
use Zzktz\LaravelHealth\Services\HealthDependencyChecker;
use Zzktz\LaravelHealth\Tests\TestCase;

class HealthApiTest extends TestCase
{
    public function testLiveEndpointDoesNotRequireBusinessDependencies(): void
    {
        $checker = Mockery::mock(HealthDependencyChecker::class);
        $checker->shouldNotReceive('mysql');
        $checker->shouldNotReceive('redis');
        $this->instance(HealthDependencyChecker::class, $checker);

        $response = $this->getJson('/health/live');

        $response->assertOk()
            ->assertJsonPath('schema_version', '1')
            ->assertJsonPath('status', 'alive')
            ->assertJsonPath('service', 'health-test')
            ->assertJsonPath('checks.app.status', 'ok')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Content-Type', 'application/json');

        $route = collect(Route::getRoutes())->first(function ($route) {
            return $route->uri() === 'health/live';
        });
        $this->assertNotNull($route);
        $this->assertNotContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
    }

    public function testDetailedEndpointsRequireBearerToken(): void
    {
        $checker = Mockery::mock(HealthDependencyChecker::class);
        $checker->shouldNotReceive('mysql');
        $checker->shouldNotReceive('redis');
        $this->instance(HealthDependencyChecker::class, $checker);

        $this->getJson('/health')->assertNotFound();
        $this->getJson('/health/ready')->assertNotFound();
    }

    public function testAllHealthyDependenciesReturnTheUnifiedContract(): void
    {
        $checker = Mockery::mock(HealthDependencyChecker::class);
        $checker->shouldReceive('mysql')->once()->with('mysql', Mockery::type('int'));
        $checker->shouldReceive('redis')->once()->with('default', Mockery::type('int'));
        $this->instance(HealthDependencyChecker::class, $checker);

        $response = $this->withHeader('Authorization', 'Bearer test-monitor-token')->getJson('/health');

        $response->assertOk()
            ->assertJsonPath('schema_version', '1')
            ->assertJsonPath('status', 'healthy')
            ->assertHeader('Cache-Control', 'no-store, private');
        $checks = $response->json('checks');
        $this->assertSame('ok', $checks['mysql.primary']['status']);
        $this->assertSame('ok', $checks['redis.default']['status']);
        $this->assertTrue($checks['mysql.primary']['required']);
    }

    public function testRequiredFailureReturns503WithoutLeakingExceptionDetails(): void
    {
        $checker = Mockery::mock(HealthDependencyChecker::class);
        $checker->shouldReceive('mysql')->once()->with('mysql', Mockery::type('int'));
        $checker->shouldReceive('redis')->once()->andThrow(new HealthCheckException(
            'authentication_failed',
            new RuntimeException('password=secret at mysql.internal:3306')
        ));
        $this->instance(HealthDependencyChecker::class, $checker);

        $response = $this->withHeader('Authorization', 'Bearer test-monitor-token')->getJson('/health/ready');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertDontSee('secret')
            ->assertDontSee('mysql.internal');
        $this->assertSame('authentication_failed', $response->json('checks')['redis.default']['error']);
    }

    public function testOptionalDependencyFailureReturnsDegradedWith200(): void
    {
        config(['health.checks.redis.0.required' => false]);
        $checker = Mockery::mock(HealthDependencyChecker::class);
        $checker->shouldReceive('mysql')->once()->with('mysql', Mockery::type('int'));
        $checker->shouldReceive('redis')->once()->andThrow(new HealthCheckException('timeout'));
        $this->instance(HealthDependencyChecker::class, $checker);

        $response = $this->withHeader('Authorization', 'Bearer test-monitor-token')
            ->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'degraded');

        $checks = $response->json('checks');
        $this->assertSame('fail', $checks['redis.default']['status']);
        $this->assertFalse($checks['redis.default']['required']);
        $this->assertSame('timeout', $checks['redis.default']['error']);
    }

    public function testInvalidConfigurationReturns503(): void
    {
        config(['health.service' => 'invalid service name']);
        $checker = Mockery::mock(HealthDependencyChecker::class);
        $checker->shouldNotReceive('mysql');
        $checker->shouldNotReceive('redis');
        $this->instance(HealthDependencyChecker::class, $checker);

        $response = $this->withHeader('Authorization', 'Bearer test-monitor-token')
            ->getJson('/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('service', 'unknown-service');

        $this->assertSame('configuration_error', $response->json('checks.configuration.error'));
    }

    public function testEmptyDependencyListsAllowAppOnlyReadiness(): void
    {
        config(['health.checks' => ['mysql' => [], 'redis' => []]]);
        $checker = Mockery::mock(HealthDependencyChecker::class);
        $checker->shouldNotReceive('mysql');
        $checker->shouldNotReceive('redis');
        $this->instance(HealthDependencyChecker::class, $checker);

        $this->withHeader('Authorization', 'Bearer test-monitor-token')
            ->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.app.status', 'ok');
    }
}
