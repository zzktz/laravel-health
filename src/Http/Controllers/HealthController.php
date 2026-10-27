<?php

namespace Zzktz\LaravelHealth\Http\Controllers;

use Zzktz\LaravelHealth\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController
{
    public function live(HealthCheckService $health): JsonResponse
    {
        return $this->json($health->live(), 200);
    }

    public function ready(Request $request, HealthCheckService $health): JsonResponse
    {
        if (!$this->authorized($request)) {
            return $this->notFound();
        }

        $payload = $health->ready();
        return $this->json($payload, $payload['status'] === 'unhealthy' ? 503 : 200);
    }

    public function show(Request $request, HealthCheckService $health): JsonResponse
    {
        return $this->ready($request, $health);
    }

    private function authorized(Request $request): bool
    {
        $token = config('health.token');
        $providedToken = $request->bearerToken();
        return is_string($token)
            && $token !== ''
            && is_string($providedToken)
            && hash_equals($token, $providedToken);
    }

    private function json(array $payload, int $status): JsonResponse
    {
        return response()->json($payload, $status, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404, [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
