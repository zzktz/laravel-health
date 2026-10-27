<?php

namespace Zzktz\LaravelHealth;

use Illuminate\Support\ServiceProvider;

class LaravelHealthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/health.php', 'health');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/health.php' => config_path('health.php'),
        ], 'laravel-health-config');

        if (config('health.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/health.php');
        }
    }
}
