<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\AuthStatusController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\BankConnectionController;
use App\Http\Controllers\Api\V1\CardController;
use App\Http\Controllers\Api\V1\CardStatementController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ImportBatchController;
use App\Http\Controllers\Api\V1\InstallmentPlanController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\RuleController;
use App\Http\Controllers\Api\V1\TagController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Middleware\EnsureBankingEnabled;
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
        Route::post('installment-plans/{plan}/cancel', [InstallmentPlanController::class, 'cancel'])->whereNumber('plan');

        // rules/order e rules/preview antes de rules/{rule}, por clareza (o
        // whereNumber já evita a colisão com esses literais).
        Route::put('rules/order', [RuleController::class, 'reorder']);
        Route::post('rules/preview', [RuleController::class, 'preview'])->middleware('throttle:60,1');
        Route::get('rules', [RuleController::class, 'index']);
        Route::post('rules', [RuleController::class, 'store']);
        Route::get('rules/{rule}', [RuleController::class, 'show'])->whereNumber('rule');
        Route::patch('rules/{rule}', [RuleController::class, 'update'])->whereNumber('rule');
        Route::delete('rules/{rule}', [RuleController::class, 'destroy'])->whereNumber('rule');
        Route::post('rules/{rule}/apply', [RuleController::class, 'apply'])->whereNumber('rule');

        Route::get('import-batches', [ImportBatchController::class, 'index']);
        Route::post('import-batches', [ImportBatchController::class, 'store'])->middleware('throttle:20,1');
        Route::get('import-batches/{batch}', [ImportBatchController::class, 'show'])->whereNumber('batch');
        Route::post('import-batches/{batch}/confirm', [ImportBatchController::class, 'confirm'])->whereNumber('batch');
        Route::delete('import-batches/{batch}', [ImportBatchController::class, 'destroy'])->whereNumber('batch');
        Route::post('import-batches/{batch}/revert', [ImportBatchController::class, 'revert'])->whereNumber('batch');

        // EnsureBankingEnabled antes de qualquer FormRequest: sem provedor
        // configurado, a rota responde 409 banking_disabled mesmo que o
        // corpo não passasse a validação de campos.
        Route::middleware(EnsureBankingEnabled::class)->group(function () {
            // connect-token antes de {connection}, por clareza (não há
            // colisão de verbo/profundidade entre os dois, mas mantém o
            // agrupamento das rotas literais perto do topo, como em rules/
            // e import-batches/).
            Route::post('bank-connections/connect-token', [BankConnectionController::class, 'connectToken'])->middleware('throttle:10,1');
            Route::get('bank-connections', [BankConnectionController::class, 'index']);
            Route::post('bank-connections', [BankConnectionController::class, 'store']);
            Route::post('bank-connections/{connection}/link-accounts', [BankConnectionController::class, 'linkAccounts'])->whereNumber('connection');
            Route::post('bank-connections/{connection}/reconnected', [BankConnectionController::class, 'reconnected'])->whereNumber('connection');
            Route::post('bank-connections/{connection}/sync', [BankConnectionController::class, 'sync'])->whereNumber('connection')->middleware('throttle:6,1');
            Route::delete('bank-connections/{connection}', [BankConnectionController::class, 'destroy'])->whereNumber('connection');
        });
    });
});
