<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Actions\CancelImportBatch;
use App\Domain\Imports\Actions\ConfirmImportBatch;
use App\Domain\Imports\Actions\RevertImportBatch;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Errors\ImportBatchNotPending;
use App\Domain\Imports\Errors\ImportBatchNotRevertible;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Queries\RevertibleBatches;

/**
 * Um lote `format = pluggy` nunca é um upload do usuário — é a
 * sincronização bancária (App\Domain\Banking\Actions\SyncTransactions), que
 * ingere na hora, sem nunca deixar passar por pending de verdade. Estes
 * testes cobrem a defesa explícita contra um lote assim aparecer pending ou
 * completed de qualquer jeito (ex.: uma falha a meio caminho antes da
 * correção) e ser confirmado/cancelado/revertido pela UI de importação, que
 * é só para uploads de arquivo.
 */
beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

it('ConfirmImportBatch rejeita um lote pluggy mesmo que esteja pending', function () {
    $batch = ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'format' => ImportFormat::Pluggy, 'status' => 'pending',
    ]);

    expect(fn () => app(ConfirmImportBatch::class)->handle($batch, []))
        ->toThrow(ImportBatchNotPending::class);
});

it('CancelImportBatch rejeita um lote pluggy mesmo que esteja pending', function () {
    $batch = ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'format' => ImportFormat::Pluggy, 'status' => 'pending',
    ]);

    expect(fn () => app(CancelImportBatch::class)->handle($batch))
        ->toThrow(ImportBatchNotPending::class);

    expect(ImportBatch::query()->whereKey($batch->id)->exists())->toBeTrue();
});

it('RevertImportBatch rejeita um lote pluggy mesmo que esteja completed', function () {
    $batch = ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'format' => ImportFormat::Pluggy, 'status' => 'completed', 'completed_at' => now(),
    ]);

    expect(fn () => app(RevertImportBatch::class)->handle($batch))
        ->toThrow(ImportBatchNotRevertible::class);
});

it('RevertibleBatches nunca inclui um lote pluggy completed', function () {
    ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'format' => ImportFormat::Pluggy, 'status' => 'completed', 'completed_at' => now(),
    ]);

    expect(app(RevertibleBatches::class)->ids())->toBe([]);
});

it('a listagem de lotes de importação nunca inclui um lote pluggy', function () {
    ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'format' => ImportFormat::Pluggy, 'status' => 'completed', 'completed_at' => now(),
    ]);
    $csv = ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'status' => 'completed', 'completed_at' => now(),
    ]);

    $this->getJson('/api/v1/import-batches')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $csv->id);
});
