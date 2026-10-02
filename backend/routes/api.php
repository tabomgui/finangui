<?php

use App\Http\Controllers\Api\V1\Auth\AuthStatusController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('auth/status', AuthStatusController::class);
    Route::post('auth/login', [SessionController::class, 'store'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [SessionController::class, 'destroy']);
        Route::get('me', [MeController::class, 'show']);
    });
});
