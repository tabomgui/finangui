<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Imports\Actions\IngestTransactions;
use App\Domain\Imports\Actions\RevertImportBatch;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Errors\ImportBatchNotRevertible;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Rules\Models\Rule;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;
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
    $this->ingest = app(IngestTransactions::class);
    $this->revert = app(RevertImportBatch::class);
});

it('reverter remove as transações inseridas, os planos e as faturas criadas pelo lote', function () {
    $this->travelTo('2026-04-01');
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    $batch = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [
            revertRow(['description' => 'Notebook Exemplo', 'amount' => 35000, 'date' => '2026-04-01', 'installment' => ['number' => 2, 'total' => 10], 'externalId' => 'ext-1']),
            revertRow(['description' => 'Mercado', 'amount' => 1000, 'date' => '2026-04-01', 'externalId' => 'ext-2']),
        ],
    );

    expect(Transaction::where('import_batch_id', $batch->id)->count())->toBe(10) // parcela 2 + projetadas 3..10 + mercado
        ->and(InstallmentPlan::count())->toBe(1)
        ->and($batch->created_statement_ids)->not->toBeEmpty();

    $this->revert->handle($batch);

    expect(Transaction::where('import_batch_id', $batch->id)->count())->toBe(0)
        ->and(InstallmentPlan::count())->toBe(0)
        ->and(CardStatement::where('account_id', $card->id)->count())->toBe(0)
        ->and($batch->refresh()->status)->toBe(ImportBatchStatus::Reverted)
        ->and($batch->reverted_at)->not->toBeNull();
});

it('reverter uma adotada faz ela voltar a ser manual, sem external_id', function () {
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    $batch = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [revertRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07', 'externalId' => 'ext-adopt'])],
    );

    $manual->refresh();
    expect($manual->external_id)->toBe('ext-adopt');

    $this->revert->handle($batch);

    $manual->refresh();
    expect($manual->external_id)->toBeNull()
        ->and($manual->original_description)->toBe('Mercado')
        ->and($manual->status->value)->toBe('posted')
        ->and(Transaction::count())->toBe(1);
});

it('reverter uma substituída volta a ser projetada, sem mexer na fatura (nunca foi tocada)', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Notebook Exemplo', 'installments' => 10, 'purchase_date' => '2026-02-03',
    ]);
    $projected = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'projected', 'installment_plan_id' => $plan->id,
        'installment_number' => 2, 'amount' => 35000, 'direction' => Direction::Out,
        'description' => 'Notebook Exemplo', 'original_description' => 'Notebook Exemplo', 'date' => '2026-04-03',
        'statement_id' => $statement->id,
    ]);

    $batch = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [revertRow([
            'description' => 'NOTEBOOK EXEMPLO LOJA', 'amount' => 35000, 'date' => '2026-04-20',
            'installment' => ['number' => 2, 'total' => 10], 'externalId' => 'card-ext-1',
        ])],
    );

    $this->revert->handle($batch);

    $projected->refresh();
    expect($projected->status->value)->toBe('projected')
        ->and($projected->external_id)->toBeNull()
        ->and($projected->source->value)->toBe('manual')
        ->and($projected->date->toDateString())->toBe('2026-04-03')
        ->and($projected->statement_id)->toBe($statement->id)
        ->and(InstallmentPlan::count())->toBe(1)
        ->and(Transaction::where('installment_plan_id', $plan->id)->count())->toBe(1);
});

it('revert recalcula a fatura via AssignStatement quando a fatura salva no undo foi excluída (ex.: prune)', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    // Fechamento bem antigo, sem relação com a data da transação: garante que
    // AssignStatement, ao reatribuir pela nova data, cria/acha uma fatura
    // diferente desta, deixando-a órfã (sem isso o teste não provaria nada,
    // já que ela continuaria em uso e não poderia ter sido excluída).
    $oldStatement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2020-01-03', 'due_date' => '2020-01-10']);
    $pendingExisting = Transaction::factory()->create([
        'account_id' => $card->id, 'external_id' => 'pend-1', 'status' => 'pending',
        'date' => '2026-01-20', 'amount' => 3000, 'direction' => Direction::Out,
        'description' => 'Compra Cartao', 'original_description' => 'Compra Cartao',
        'statement_id' => $oldStatement->id,
    ]);

    $batch = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [revertRow(['description' => 'Compra Cartao', 'amount' => 3000, 'date' => '2026-01-25', 'externalId' => 'pend-1'])],
    );

    $pendingExisting->refresh();
    expect($pendingExisting->status->value)->toBe('posted')
        ->and($pendingExisting->statement_id)->not->toBe($oldStatement->id);

    // Simula a fatura antiga (agora vazia) tendo sido excluída depois da importação.
    CardStatement::whereKey($oldStatement->id)->delete();

    $this->revert->handle($batch);

    $pendingExisting->refresh();
    expect($pendingExisting->status->value)->toBe('pending')
        ->and($pendingExisting->date->toDateString())->toBe('2026-01-20')
        ->and($pendingExisting->statement_id)->not->toBeNull()
        ->and(CardStatement::whereKey($oldStatement->id)->exists())->toBeFalse();
});

it('update: ingestão guarda status/valor/data/fatura antigos e revert restaura tudo', function () {
    $pendingExisting = Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'pend-x', 'status' => 'pending',
        'date' => '2026-03-10', 'amount' => 5000, 'direction' => Direction::Out,
        'description' => 'Compra Mercado', 'original_description' => 'Compra Mercado',
    ]);

    $batch = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [revertRow(['description' => 'Compra Mercado', 'amount' => 5010, 'date' => '2026-03-11', 'externalId' => 'pend-x'])],
    );

    expect($batch->stats['updated'])->toBe(1);

    $pendingExisting->refresh();
    expect($pendingExisting->status->value)->toBe('posted')
        ->and($pendingExisting->amount->cents)->toBe(5010)
        ->and($pendingExisting->date->toDateString())->toBe('2026-03-11');

    $this->revert->handle($batch);

    $pendingExisting->refresh();
    expect($pendingExisting->status->value)->toBe('pending')
        ->and($pendingExisting->amount->cents)->toBe(5000)
        ->and($pendingExisting->date->toDateString())->toBe('2026-03-10')
        ->and($pendingExisting->statement_id)->toBeNull();
});

it('swap_pending: ingestão troca o id e revert restaura o id antigo e volta a pending', function () {
    $pending = Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'old-id', 'status' => 'pending',
        'description' => 'Compra Mercado', 'original_description' => 'Compra Mercado',
        'amount' => 3000, 'direction' => Direction::Out, 'date' => '2026-03-10',
    ]);

    $batch = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [revertRow(['description' => 'COMPRA MERCADO EXTRA', 'amount' => 3000, 'date' => '2026-03-10', 'externalId' => 'new-id'])],
    );

    expect($batch->stats['swapped'])->toBe(1);

    $pending->refresh();
    expect($pending->external_id)->toBe('new-id')
        ->and($pending->status->value)->toBe('posted');

    $this->revert->handle($batch);

    $pending->refresh();
    expect($pending->external_id)->toBe('old-id')
        ->and($pending->status->value)->toBe('pending');
});

it('revert exclui os vínculos de tag das transações inseridas pelo lote', function () {
    $tag = Tag::factory()->create(['user_id' => $this->user->id]);
    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'mercado']],
        'actions' => [['type' => 'add_tag', 'tag_id' => $tag->id]],
    ]);

    $batch = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [revertRow(['description' => 'Compra Mercado', 'externalId' => 'tag-1'])],
    );

    $transaction = Transaction::where('external_id', 'tag-1')->first();
    expect(DB::table('tag_transaction')->where('transaction_id', $transaction->id)->count())->toBe(1);

    $this->revert->handle($batch);

    expect(DB::table('tag_transaction')->where('transaction_id', $transaction->id)->count())->toBe(0);
});

it('só permite reverter o lote mais recente concluído da conta', function () {
    $batch1 = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id]),
        [revertRow(['externalId' => 'a'])],
    );
    $batch2 = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id]),
        [revertRow(['externalId' => 'b'])],
    );

    expect(fn () => $this->revert->handle($batch1))->toThrow(ImportBatchNotRevertible::class);

    $this->revert->handle($batch2);
    $this->revert->handle($batch1);

    expect(ImportBatch::whereIn('id', [$batch1->id, $batch2->id])->where('status', 'reverted')->count())->toBe(2);
});

it('reverter duas vezes dá 409', function () {
    $batch = $this->ingest->handle(
        ImportBatch::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id]),
        [revertRow(['externalId' => 'once'])],
    );

    $this->revert->handle($batch);

    expect(fn () => $this->revert->handle($batch))->toThrow(ImportBatchNotRevertible::class);
});

it('reverter um lote pendente dá 409', function () {
    $batch = ImportBatch::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
    ]);

    expect(fn () => $this->revert->handle($batch))->toThrow(ImportBatchNotRevertible::class);
});
