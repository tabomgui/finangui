<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\AuthStatusController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CardController;
use App\Http\Controllers\Api\V1\CardStatementController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InstallmentPlanController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Middleware\EnsureRegistrationAllowed;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('auth/status', AuthStatusController::class);
    Route::post('auth/login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::post('auth/register', RegisterController::class)
        ->middleware(['throttle:10,1', EnsureRegistrationAllowed::class]);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [SessionController::class, 'destroy']);
        Route::get('me', [MeController::class, 'show']);
        Route::patch('me', [MeController::class, 'update']);
        Route::get('dashboard', DashboardController::class);

        // Updates são parciais (PATCH): apiResource()->except('update') tira o
        // PUT/PATCH padrão (que registraria os dois verbos) e o Route::patch
        // abaixo registra só o verbo correto, pra bater com a doc OpenAPI.
        Route::apiResource('accounts', AccountController::class)->except('update');
        Route::patch('accounts/{account}', [AccountController::class, 'update']);
        Route::apiResource('categories', CategoryController::class)->except('update');
        Route::patch('categories/{category}', [CategoryController::class, 'update']);
        Route::apiResource('tags', TagController::class)->except(['show', 'update']);
        Route::patch('tags/{tag}', [TagController::class, 'update']);
        Route::apiResource('transactions', TransactionController::class)->except('update');
        Route::patch('transactions/{transaction}', [TransactionController::class, 'update']);
        Route::post('transfers', [TransferController::class, 'store']);
        Route::get('transfers/{transfer}', [TransferController::class, 'show'])->whereUuid('transfer');
        Route::patch('transfers/{transfer}', [TransferController::class, 'update'])->whereUuid('transfer');
        Route::delete('transfers/{transfer}', [TransferController::class, 'destroy'])->whereUuid('transfer');

        Route::get('cards', [CardController::class, 'index']);
        Route::get('cards/{account}', [CardController::class, 'show'])->whereNumber('account');
        Route::get('cards/{account}/statements', [CardController::class, 'statements'])->whereNumber('account');
        Route::get('cards/{account}/statement-preview', [CardController::class, 'statementPreview'])->whereNumber('account');
        Route::get('cards/{account}/installment-plans', [CardController::class, 'installmentPlans'])->whereNumber('account');
        Route::get('card-statements/{statement}', [CardStatementController::class, 'show'])->whereNumber('statement');
        Route::patch('card-statements/{statement}', [CardStatementController::class, 'update'])->whereNumber('statement');
        Route::post('card-statements/{statement}/payments', [CardStatementController::class, 'pay'])->whereNumber('statement');
        Route::patch('installment-plans/{plan}', [InstallmentPlanController::class, 'update'])->whereNumber('plan');
        Route::delete('installment-plans/{plan}', [InstallmentPlanController::class, 'destroy'])->whereNumber('plan');
    });
});
