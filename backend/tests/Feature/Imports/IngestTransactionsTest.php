<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Imports\Actions\IngestTransactions;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Imports\Errors\ImportBatchNotPending;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\FormatDetector;
use App\Domain\Imports\Support\IngestionPlanner;
use App\Domain\Rules\Models\Rule;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

function ingestRow(array $overrides = []): ParsedRow
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

function pendingBatch(array $overrides = []): ImportBatch
{
    return ImportBatch::factory()->create($overrides);
}

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->planner = new IngestionPlanner;
    $this->action = app(IngestTransactions::class);
});

it('importa nubank.csv: cada linha válida vira uma transação nova com source csv e import_batch_id preenchido', function () {
    $parsed = FormatDetector::parse(importFixture('nubank.csv'), ImportFormat::Nubank, false)['result'];
    $decisions = $this->planner->plan($this->account, $parsed->rows);
    $batch = pendingBatch([
        'account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank,
    ]);

    $result = $this->action->handle($batch, $this->account, $decisions);

    expect($result['stats']['inserted'])->toBe(count($parsed->rows))
        ->and(Transaction::where('import_batch_id', $batch->id)->count())->toBe(count($parsed->rows));

    Transaction::where('import_batch_id', $batch->id)->get()->each(function (Transaction $t) {
        expect($t->source)->toBe(TransactionSource::Csv)
            ->and($t->description)->toBe($t->original_description);
    });
});

it('é idempotente: importar o mesmo arquivo duas vezes faz a segunda vez tudo duplicate, sem transação nova', function () {
    $rows = FormatDetector::parse(importFixture('nubank.csv'), ImportFormat::Nubank, false)['result']->rows;

    $batch1 = pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]);
    $this->action->handle($batch1, $this->account, $this->planner->plan($this->account, $rows));

    $totalFirstImport = Transaction::count();

    $batch2 = pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]);
    $result2 = $this->action->handle($batch2, $this->account, $this->planner->plan($this->account, $rows));

    expect($result2['stats']['inserted'])->toBe(0)
        ->and($result2['stats']['duplicates'])->toBe($totalFirstImport)
        ->and(Transaction::count())->toBe($totalFirstImport);
});

it('adoção mantém categoria, descrição, notas e tags do manual e grava external_id, source, status e original_description', function () {
    $tag = Tag::factory()->create(['user_id' => $this->user->id]);
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'notes' => 'Nota pessoal', 'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
        'category_id' => $category->id, 'categorized_by' => 'manual',
    ]);
    $manual->tags()->sync([$tag->id]);

    $row = ingestRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07', 'externalId' => 'ext-adopt']);
    $decisions = $this->planner->plan($this->account, [$row]);
    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt);

    $batch = pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]);
    $result = $this->action->handle($batch, $this->account, $decisions);

    expect($result['stats']['adopted'])->toBe(1);

    $manual->refresh();
    expect($manual->external_id)->toBe('ext-adopt')
        ->and($manual->source)->toBe(TransactionSource::Csv)
        ->and($manual->status)->toBe(TransactionStatus::Posted)
        ->and($manual->original_description)->toBe('COMPRA MERCADO EXEMPLO')
        ->and($manual->description)->toBe('Mercado')
        ->and($manual->notes)->toBe('Nota pessoal')
        ->and($manual->category_id)->toBe($category->id)
        ->and($manual->categorized_by)->toBe('manual')
        ->and($manual->tags->pluck('id')->all())->toBe([$tag->id])
        ->and($manual->import_batch_id)->toBeNull();
});

it('cartão: compra comum ganha fatura e parcela nova sem plano cria o plano e projeta as próximas', function () {
    $this->travelTo('2026-04-01');

    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $parsed = FormatDetector::parse(importFixture('nubank-card.csv'), ImportFormat::NubankCard, true)['result'];
    $decisions = $this->planner->plan($card, $parsed->rows);
    $batch = pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]);

    $result = $this->action->handle($batch, $card, $decisions);

    expect($result['stats']['inserted'])->toBe(count($parsed->rows));

    $simple = Transaction::where('account_id', $card->id)->where('description', 'Loja Ficticia')->first();
    expect($simple)->not->toBeNull()
        ->and($simple->statement_id)->not->toBeNull();

    $plan = InstallmentPlan::where('account_id', $card->id)->first();
    expect($plan)->not->toBeNull()
        ->and($plan->installments)->toBe(10)
        ->and($plan->purchase_date->toDateString())->toBe('2026-02-03')
        ->and($plan->import_batch_id)->toBe($batch->id);

    $parcels = Transaction::where('installment_plan_id', $plan->id)->orderBy('installment_number')->get();
    expect($parcels)->toHaveCount(9) // parcelas 2 (da linha) .. 10
        ->and($parcels->first()->installment_number)->toBe(2)
        ->and($parcels->first()->status)->toBe(TransactionStatus::Posted)
        ->and($parcels->first()->source)->toBe(TransactionSource::Csv)
        ->and($parcels->last()->installment_number)->toBe(10)
        ->and($parcels->last()->source)->toBe(TransactionSource::Installment)
        ->and($parcels->last()->status)->toBe(TransactionStatus::Projected);

    $parcels->each(fn (Transaction $t) => expect($t->import_batch_id)->toBe($batch->id));
});

it('parcela que casa com uma projetada existente substitui em vez de criar plano novo', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Notebook Exemplo', 'installments' => 10, 'purchase_date' => '2026-02-03',
    ]);
    $projected = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'projected', 'installment_plan_id' => $plan->id,
        'installment_number' => 2, 'amount' => 35000, 'direction' => Direction::Out,
        'description' => 'Notebook Exemplo', 'original_description' => 'Notebook Exemplo', 'date' => '2026-04-03',
    ]);

    $row = ingestRow([
        'description' => 'NOTEBOOK EXEMPLO LOJA', 'amount' => 35000, 'date' => '2026-04-05',
        'installment' => ['number' => 2, 'total' => 10], 'externalId' => 'card-ext-1',
    ]);
    $decisions = $this->planner->plan($card, [$row]);
    expect($decisions[0]->outcome)->toBe(RowOutcome::ReplaceInstallment)
        ->and($decisions[0]->transactionId)->toBe($projected->id);

    $batch = pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]);
    $result = $this->action->handle($batch, $card, $decisions);

    expect($result['stats']['replaced'])->toBe(1)
        ->and(InstallmentPlan::count())->toBe(1);

    $projected->refresh();
    expect($projected->status)->toBe(TransactionStatus::Posted)
        ->and($projected->external_id)->toBe('card-ext-1')
        ->and($projected->source)->toBe(TransactionSource::Csv)
        ->and($projected->date->toDateString())->toBe('2026-04-05')
        ->and($projected->import_batch_id)->toBeNull();
});

it('regra ativa categoriza as transações novas; sem regra, usa o histórico', function () {
    $categoryRule = Category::factory()->create(['user_id' => $this->user->id]);
    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'mercado']],
        'actions' => [['type' => 'set_category', 'category_id' => $categoryRule->id]],
    ]);

    $categoryHistory = Category::factory()->create(['user_id' => $this->user->id]);
    Transaction::factory()->count(2)->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'description' => 'Padaria Joao', 'direction' => 'out', 'category_id' => $categoryHistory->id,
    ]);

    $decisions = $this->planner->plan($this->account, [
        ingestRow(['description' => 'Compra Mercado Exemplo', 'amount' => 1000, 'externalId' => 'r1']),
        ingestRow(['description' => 'Padaria Joao 99', 'amount' => 2000, 'externalId' => 'r2']),
    ]);
    $batch = pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]);
    $this->action->handle($batch, $this->account, $decisions);

    $byRule = Transaction::where('external_id', 'r1')->first();
    $byHistory = Transaction::where('external_id', 'r2')->first();

    expect($byRule->category_id)->toBe($categoryRule->id)
        ->and($byRule->categorized_by)->toStartWith('rule:')
        ->and($byHistory->category_id)->toBe($categoryHistory->id)
        ->and($byHistory->categorized_by)->toBe('history');
});

it('é atômico: um erro no meio não deixa nada gravado', function () {
    $decisions = [
        new RowDecision(ingestRow(['externalId' => 'ok-1']), RowOutcome::New),
        new RowDecision(ingestRow(['externalId' => 'broken']), RowOutcome::Update, 999999),
    ];
    $batch = pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]);

    expect(fn () => $this->action->handle($batch, $this->account, $decisions))
        ->toThrow(ModelNotFoundException::class);

    expect(Transaction::count())->toBe(0);
});

it('lança ImportBatchNotPending quando o lote não está pendente', function () {
    $batch = pendingBatch([
        'account_id' => $this->account->id, 'user_id' => $this->user->id, 'status' => 'completed',
    ]);

    expect(fn () => $this->action->handle($batch, $this->account, []))
        ->toThrow(ImportBatchNotPending::class);
});
