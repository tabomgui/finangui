<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

it('toda rota da API v1 exige auth:sanctum, exceto as públicas', function () {
    $public = ['api/v1/auth/status', 'api/v1/auth/login', 'api/v1/auth/register'];

    $unprotected = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/v1/'))
        ->reject(fn (Route $route) => in_array($route->uri(), $public, true))
        ->reject(fn (Route $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
        ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});
