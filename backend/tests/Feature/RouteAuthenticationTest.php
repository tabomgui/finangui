<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

it('toda rota da API exige auth:sanctum, exceto as públicas', function () {
    $public = [
        'GET api/v1/auth/status',
        'POST api/v1/auth/login',
        'POST api/v1/auth/register',
        // Rotas web do OAuth Google (routes/web.php): públicas por natureza, ficam fora do
        // grupo api/v1 e não passam por auth:sanctum de propósito.
        'GET api/auth/google/redirect',
        'GET api/auth/google/callback',
    ];

    $apiRoutes = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'));

    expect($apiRoutes)->not->toBeEmpty();

    $unprotected = $apiRoutes
        ->flatMap(function (Route $route) {
            $isProtected = in_array('auth:sanctum', $route->gatherMiddleware(), true)
                && ! in_array('auth:sanctum', $route->excludedMiddleware(), true);

            return collect($route->methods())
                ->reject(fn (string $method) => $method === 'HEAD')
                ->map(fn (string $method) => [
                    'key' => $method.' '.$route->uri(),
                    'protected' => $isProtected,
                ]);
        })
        ->reject(fn (array $entry) => in_array($entry['key'], $public, true))
        ->reject(fn (array $entry) => $entry['protected'])
        ->map(fn (array $entry) => $entry['key'])
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});
