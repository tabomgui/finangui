<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Actions\CancelImportBatch;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Errors\ImportBatchNotPending;
use App\Domain\Imports\Models\ImportBatch;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->cancel = app(CancelImportBatch::class);
});

it('cancela um lote pendente', function () {
    $batch = ImportBatch::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id]);

    $this->cancel->handle($batch);

    expect(ImportBatch::query()->whereKey($batch->id)->exists())->toBeFalse();
});

it('lança ImportBatchNotPending quando o lote não está mais pendente', function () {
    $batch = ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id, 'status' => ImportBatchStatus::Completed,
    ]);

    expect(fn () => $this->cancel->handle($batch))->toThrow(ImportBatchNotPending::class);
    expect(ImportBatch::query()->whereKey($batch->id)->exists())->toBeTrue();
});

it('delete atômico: cancelar duas vezes a mesma instância em memória só apaga na primeira, a segunda dá 409', function () {
    // Mesma instância $batch (status "pending" em memória) passada duas
    // vezes: um delete() ingênuo (ler, conferir status em PHP, só então
    // apagar) apagaria de novo na segunda chamada, já que $batch->status
    // nunca mudou em memória. O delete atômico (status = pending na própria
    // cláusula) é que impede isso.
    $batch = ImportBatch::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id]);

    $this->cancel->handle($batch);

    expect(fn () => $this->cancel->handle($batch))->toThrow(ImportBatchNotPending::class);
});
