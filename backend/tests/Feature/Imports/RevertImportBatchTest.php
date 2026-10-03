<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Imports\Actions\IngestTransactions;
use App\Domain\Imports\Actions\RevertImportBatch;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Imports\Errors\ImportBatchNotRevertible;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\IngestionPlanner;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Str;

function revertRow(array $overrides = []): ParsedRow
{
    return new ParsedRow(
        line: $overrides['line'] ?? 1,
        date: $overrides['date'] ?? '2026-03-07',
        amount: $overrides['amount'] ?? 1000,
        direction: $overrides['direction'] ?? Direction::Out,
        description: $overrides['description'] ?? 'Compra',
        externalId: $overrides['externalId'] ?? ('h:'.Str::random(12)),
        installment: $overrides['installment'] ?? null,
        pending: $overrides['pending'] ?? false,
    );
}

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->planner = new IngestionPlanner;
    $this->ingest = app(IngestTransactions::class);
    $this->revert = app(RevertImportBatch::class);
});

it('reverter remove as transações inseridas e os planos criados pelo lote', function () {
    $this->travelTo('2026-04-01');
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    $decisions = $this->planner->plan($card, [
        revertRow(['description' => 'Notebook Exemplo', 'amount' => 35000, 'date' => '2026-04-01', 'installment' => ['number' => 2, 'total' => 10], 'externalId' => 'ext-1']),
        revertRow(['description' => 'Mercado', 'amount' => 1000, 'date' => '2026-04-01', 'externalId' => 'ext-2']),
    ]);
    $batch = ImportBatch::factory()->create([
        'account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard,
    ]);
    $result = $this->ingest->handle($batch, $card, $decisions);
    $batch->update(['status' => 'completed', 'completed_at' => now(), 'stats' => $result['stats'], 'undo' => $result['undo'], 'rows' => []]);

    expect(Transaction::where('import_batch_id', $batch->id)->count())->toBe(10) // parcela 2 + projetadas 3..10 + mercado
        ->and(InstallmentPlan::count())->toBe(1);

    $this->revert->handle($batch);

    expect(Transaction::where('import_batch_id', $batch->id)->count())->toBe(0)
        ->and(InstallmentPlan::count())->toBe(0)
        ->and($batch->refresh()->status)->toBe(ImportBatchStatus::Reverted)
        ->and($batch->reverted_at)->not->toBeNull();
});

it('reverter uma adotada faz ela voltar a ser manual, sem external_id', function () {
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($this->account, [
        revertRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07', 'externalId' => 'ext-adopt']),
    ]);
    $batch = ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank,
    ]);
    $result = $this->ingest->handle($batch, $this->account, $decisions);
    $batch->update(['status' => 'completed', 'completed_at' => now(), 'stats' => $result['stats'], 'undo' => $result['undo'], 'rows' => []]);

    $manual->refresh();
    expect($manual->external_id)->toBe('ext-adopt');

    $this->revert->handle($batch);

    $manual->refresh();
    expect($manual->external_id)->toBeNull()
        ->and($manual->original_description)->toBe('Mercado')
        ->and($manual->status->value)->toBe('posted')
        ->and(Transaction::count())->toBe(1);
});

it('reverter uma substituída volta a ser projetada', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Notebook Exemplo', 'installments' => 10, 'purchase_date' => '2026-02-03',
    ]);
    $projected = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'projected', 'installment_plan_id' => $plan->id,
        'installment_number' => 2, 'amount' => 35000, 'direction' => Direction::Out,
        'description' => 'Notebook Exemplo', 'original_description' => 'Notebook Exemplo', 'date' => '2026-04-03',
        'statement_id' => null,
    ]);

    $decisions = $this->planner->plan($card, [
        revertRow([
            'description' => 'NOTEBOOK EXEMPLO LOJA', 'amount' => 35000, 'date' => '2026-04-05',
            'installment' => ['number' => 2, 'total' => 10], 'externalId' => 'card-ext-1',
        ]),
    ]);
    expect($decisions[0]->outcome)->toBe(RowOutcome::ReplaceInstallment);

    $batch = ImportBatch::factory()->create([
        'account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard,
    ]);
    $result = $this->ingest->handle($batch, $card, $decisions);
    $batch->update(['status' => 'completed', 'completed_at' => now(), 'stats' => $result['stats'], 'undo' => $result['undo'], 'rows' => []]);

    $this->revert->handle($batch);

    $projected->refresh();
    expect($projected->status->value)->toBe('projected')
        ->and($projected->external_id)->toBeNull()
        ->and($projected->date->toDateString())->toBe('2026-04-03')
        ->and(InstallmentPlan::count())->toBe(1)
        ->and(Transaction::where('installment_plan_id', $plan->id)->count())->toBe(1);
});

it('reverter duas vezes dá 409', function () {
    $batch = ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id, 'status' => 'completed', 'completed_at' => now(),
        'stats' => [], 'undo' => [],
    ]);

    $this->revert->handle($batch);

    expect(fn () => $this->revert->handle($batch))->toThrow(ImportBatchNotRevertible::class);
});

it('reverter um lote pendente dá 409', function () {
    $batch = ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
    ]);

    expect(fn () => $this->revert->handle($batch))->toThrow(ImportBatchNotRevertible::class);
});
