<?php

return [
    'enabled' => env('HEALTH_ENABLED', true),
    'service' => env('HEALTH_SERVICE_NAME', env('APP_NAME', 'laravel-app')),
    'token' => env('HEALTH_TOKEN', ''),
    'timeout_ms' => (int) env('HEALTH_TIMEOUT_MS', 4000),
    'middleware' => [],
    'checks' => [
        'mysql' => [
            ['name' => 'primary', 'connection' => 'mysql', 'required' => true],
        ],
        'redis' => [
            ['name' => 'default', 'connection' => 'default', 'required' => true],
        ],
    ],
];
