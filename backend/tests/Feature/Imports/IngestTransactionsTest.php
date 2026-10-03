<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Imports\Actions\IngestTransactions;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Enums\ImportBatchStatus;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Errors\ImportBatchNotPending;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Imports\Support\FormatDetector;
use App\Domain\Rules\Models\Rule;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
    $this->action = app(IngestTransactions::class);
});

it('importa nubank.csv e devolve o lote concluído: stats, import_batch_id e rows esvaziado', function () {
    $parsed = FormatDetector::parse(importFixture('nubank.csv'), ImportFormat::Nubank, false)['result'];
    $batch = pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank, 'rows' => ['algo']]);

    $result = $this->action->handle($batch, $parsed->rows);

    expect($result->status)->toBe(ImportBatchStatus::Completed)
        ->and($result->completed_at)->not->toBeNull()
        ->and($result->rows)->toBeNull()
        ->and($result->stats['inserted'])->toBe(count($parsed->rows))
        ->and(Transaction::where('import_batch_id', $batch->id)->count())->toBe(count($parsed->rows));

    Transaction::where('import_batch_id', $batch->id)->get()->each(function (Transaction $t) {
        expect($t->source)->toBe(TransactionSource::Csv)
            ->and($t->description)->toBe($t->original_description);
    });
});

it('é idempotente: importar o mesmo arquivo duas vezes faz a segunda vez tudo duplicate, sem transação nova', function () {
    $rows = FormatDetector::parse(importFixture('nubank.csv'), ImportFormat::Nubank, false)['result']->rows;

    $this->action->handle(pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]), $rows);
    $totalFirstImport = Transaction::count();

    $result2 = $this->action->handle(pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]), $rows);

    expect($result2->stats['inserted'])->toBe(0)
        ->and($result2->stats['duplicates'])->toBe($totalFirstImport)
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

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [ingestRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07', 'externalId' => 'ext-adopt'])],
    );

    expect($batch->stats['adopted'])->toBe(1);

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

    // status já era posted (default da factory): não entra no undo, só o que de fato mudou.
    expect($batch->undo)->toBe([
        ['transaction_id' => $manual->id, 'attributes' => [
            'external_id' => null, 'source' => 'manual', 'original_description' => 'Mercado',
        ]],
    ]);
});

it('cartão: compra comum ganha fatura e parcela nova sem plano cria o plano e projeta as próximas', function () {
    $this->travelTo('2026-04-01');

    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $parsed = FormatDetector::parse(importFixture('nubank-card.csv'), ImportFormat::NubankCard, true)['result'];
    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        $parsed->rows,
    );

    expect($batch->stats['inserted'])->toBe(count($parsed->rows));

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
        ->and($parcels->first()->date->toDateString())->toBe('2026-03-03') // parcela nova: confia na data da linha
        ->and($parcels->first()->status)->toBe(TransactionStatus::Posted)
        ->and($parcels->first()->source)->toBe(TransactionSource::Csv)
        ->and($parcels->last()->installment_number)->toBe(10)
        ->and($parcels->last()->source)->toBe(TransactionSource::Installment)
        ->and($parcels->last()->status)->toBe(TransactionStatus::Projected);

    $parcels->each(fn (Transaction $t) => expect($t->import_batch_id)->toBe($batch->id));
    expect($batch->created_statement_ids)->not->toBeEmpty();
});

it('duas parcelas novas do mesmo parcelamento no mesmo arquivo criam só um plano (a segunda substitui a parcela projetada)', function () {
    $this->travelTo('2026-03-10');
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [
            ingestRow(['description' => 'Notebook Exemplo', 'amount' => 35000, 'date' => '2026-03-03', 'installment' => ['number' => 2, 'total' => 10], 'externalId' => 'nb-2']),
            ingestRow(['description' => 'Notebook Exemplo', 'amount' => 35000, 'date' => '2026-04-05', 'installment' => ['number' => 3, 'total' => 10], 'externalId' => 'nb-3']),
        ],
    );

    expect(InstallmentPlan::where('account_id', $card->id)->count())->toBe(1)
        ->and($batch->stats['inserted'])->toBe(1)
        ->and($batch->stats['replaced'])->toBe(1);

    $plan = InstallmentPlan::where('account_id', $card->id)->first();
    expect(Transaction::where('installment_plan_id', $plan->id)->count())->toBe(9);

    $third = Transaction::where('installment_plan_id', $plan->id)->where('installment_number', 3)->first();
    expect($third->external_id)->toBe('nb-3')
        ->and($third->source)->toBe(TransactionSource::Csv)
        ->and($third->status)->toBe(TransactionStatus::Posted)
        ->and($third->date->toDateString())->toBe('2026-04-05');
});

it('replace_installment (parcela já existente no banco) mantém statement_id mesmo quando a data muda; só date/status/external_id/source mudam', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-04-03', 'due_date' => '2026-04-10']);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Notebook Exemplo', 'installments' => 10, 'purchase_date' => '2026-02-03',
    ]);
    $projected = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'projected', 'installment_plan_id' => $plan->id,
        'installment_number' => 2, 'amount' => 35000, 'direction' => Direction::Out,
        'description' => 'Notebook Exemplo', 'original_description' => 'Notebook Exemplo', 'date' => '2026-04-03',
        'statement_id' => $statement->id,
    ]);

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [ingestRow([
            'description' => 'NOTEBOOK EXEMPLO LOJA', 'amount' => 35000, 'date' => '2026-04-20',
            'installment' => ['number' => 2, 'total' => 10], 'externalId' => 'card-ext-1',
        ])],
    );

    expect($batch->stats['replaced'])->toBe(1)
        ->and(InstallmentPlan::count())->toBe(1);

    $projected->refresh();
    expect($projected->status)->toBe(TransactionStatus::Posted)
        ->and($projected->external_id)->toBe('card-ext-1')
        ->and($projected->source)->toBe(TransactionSource::Csv)
        ->and($projected->date->toDateString())->toBe('2026-04-20')
        ->and($projected->statement_id)->toBe($statement->id)
        ->and($projected->import_batch_id)->toBeNull();

    expect($batch->undo)->toBe([
        ['transaction_id' => $projected->id, 'attributes' => [
            'external_id' => null, 'source' => 'manual', 'status' => 'projected', 'date' => '2026-04-03',
        ]],
    ]);
});

it('parcelas projetadas herdam descrição, favorecido, ignorada, categoria e tags da parcela categorizada; original_description continua a da própria linha', function () {
    $this->travelTo('2026-03-10');
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $tag = Tag::factory()->create(['user_id' => $this->user->id]);

    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'notebook']],
        'actions' => [
            ['type' => 'set_category', 'category_id' => $category->id],
            ['type' => 'set_description', 'value' => 'Notebook (parcelado)'],
            ['type' => 'add_tag', 'tag_id' => $tag->id],
            ['type' => 'ignore'],
        ],
    ]);

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [ingestRow(['description' => 'Notebook Exemplo', 'amount' => 35000, 'date' => '2026-03-03', 'installment' => ['number' => 2, 'total' => 5], 'externalId' => 'nb-1'])],
    );

    $plan = InstallmentPlan::where('account_id', $card->id)->first();
    $parcelN = Transaction::where('installment_plan_id', $plan->id)->where('installment_number', 2)->first();
    $lastParcel = Transaction::where('installment_plan_id', $plan->id)->where('installment_number', 5)->first();

    expect($parcelN->description)->toBe('Notebook (parcelado)')
        ->and($parcelN->is_ignored)->toBeTrue()
        ->and($parcelN->category_id)->toBe($category->id);

    expect($lastParcel->description)->toBe('Notebook (parcelado)')
        ->and($lastParcel->original_description)->toBe('Notebook Exemplo')
        ->and($lastParcel->payee)->toBeNull()
        ->and($lastParcel->is_ignored)->toBeTrue()
        ->and($lastParcel->category_id)->toBe($category->id)
        ->and($lastParcel->categorized_by)->toBe($parcelN->categorized_by)
        ->and($lastParcel->tags->pluck('id')->all())->toBe([$tag->id]);
});

it('só cria plano para linha de saída: installment numa linha de entrada é ignorado', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [ingestRow(['description' => 'Estorno Loja', 'amount' => 1000, 'direction' => Direction::In, 'installment' => ['number' => 1, 'total' => 3], 'externalId' => 'in-1'])],
    );

    expect(InstallmentPlan::count())->toBe(0)
        ->and($batch->stats['inserted'])->toBe(1);

    $transaction = Transaction::where('external_id', 'in-1')->first();
    expect($transaction->installment_plan_id)->toBeNull();
});

it('regra ativa categoriza as transações novas; sem regra, usa o histórico; as regras ativas são carregadas uma única vez por lote', function () {
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

    DB::enableQueryLog();

    $this->action->handle(
        pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [
            ingestRow(['description' => 'Compra Mercado Exemplo', 'amount' => 1000, 'externalId' => 'r1']),
            ingestRow(['description' => 'Padaria Joao 99', 'amount' => 2000, 'externalId' => 'r2']),
            ingestRow(['description' => 'Outra Coisa Qualquer', 'amount' => 3000, 'externalId' => 'r3']),
        ],
    );

    $ruleQueries = array_filter(DB::getQueryLog(), fn (array $entry) => str_contains($entry['query'], '"rules"'));
    DB::disableQueryLog();

    expect($ruleQueries)->toHaveCount(1);

    $byRule = Transaction::where('external_id', 'r1')->first();
    $byHistory = Transaction::where('external_id', 'r2')->first();

    expect($byRule->category_id)->toBe($categoryRule->id)
        ->and($byRule->categorized_by)->toStartWith('rule:')
        ->and($byHistory->category_id)->toBe($categoryHistory->id)
        ->and($byHistory->categorized_by)->toBe('history');
});

it('mescla failed e skipped no stats final, mesmo não vindo do planner', function () {
    $batch = $this->action->handle(
        pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [ingestRow(['externalId' => 'r1'])],
        ['failed' => [['line' => 5, 'reason' => 'Data inválida.']], 'skipped' => 2],
    );

    expect($batch->stats['inserted'])->toBe(1)
        ->and($batch->stats['failed'])->toBe([['line' => 5, 'reason' => 'Data inválida.']])
        ->and($batch->stats['skipped'])->toBe(2);
});

it('é atômico: um erro no meio não deixa nada gravado', function () {
    $rows = [
        ingestRow(['externalId' => 'ok-1']),
        ingestRow(['externalId' => 'broken', 'amount' => 0]),
    ];
    $batch = pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]);

    expect(fn () => $this->action->handle($batch, $rows))->toThrow(QueryException::class);

    expect(Transaction::count())->toBe(0)
        ->and($batch->refresh()->status)->toBe(ImportBatchStatus::Pending);
});

it('lança ImportBatchNotPending quando o lote não está pendente', function () {
    $batch = pendingBatch([
        'account_id' => $this->account->id, 'user_id' => $this->user->id, 'status' => 'completed',
    ]);

    expect(fn () => $this->action->handle($batch, []))
        ->toThrow(ImportBatchNotPending::class);
});
