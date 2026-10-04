<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\PayStatement;
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
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
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
        meta: $overrides['meta'] ?? [],
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

it('detecta e liga a transferência cuja outra perna já existe (de um lote anterior de outra conta)', function () {
    $savings = Account::factory()->create(['user_id' => $this->user->id]);

    $this->action->handle(
        pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [ingestRow(['description' => 'Transferência enviada', 'amount' => 20000, 'direction' => Direction::Out, 'date' => '2026-03-10', 'externalId' => 'out-1'])],
    );

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $savings->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [ingestRow(['description' => 'Transferência recebida', 'amount' => 20000, 'direction' => Direction::In, 'date' => '2026-03-10', 'externalId' => 'in-1'])],
    );

    expect($batch->stats['transfers_linked'])->toBe(1)
        ->and($batch->stats['transfer_suggestions'])->toBe(0);

    $out = Transaction::where('external_id', 'out-1')->first();
    $in = Transaction::where('external_id', 'in-1')->first();
    expect($out->transfer_id)->not->toBeNull()
        ->and($in->transfer_id)->toBe($out->transfer_id);
});

it('transferência ambígua entra em transfer_suggestions nas estatísticas do lote, sem ligar', function () {
    $savings = Account::factory()->create(['user_id' => $this->user->id]);
    $other = Account::factory()->create(['user_id' => $this->user->id]);

    $this->action->handle(
        pendingBatch(['account_id' => $savings->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [ingestRow(['description' => 'Lancamento comum', 'amount' => 15000, 'direction' => Direction::In, 'date' => '2026-03-10', 'externalId' => 'in-amb-1'])],
    );
    $this->action->handle(
        pendingBatch(['account_id' => $other->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [ingestRow(['description' => 'Lancamento comum', 'amount' => 15000, 'direction' => Direction::In, 'date' => '2026-03-10', 'externalId' => 'in-amb-2'])],
    );

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Nubank]),
        [ingestRow(['description' => 'Lancamento comum', 'amount' => 15000, 'direction' => Direction::Out, 'date' => '2026-03-10', 'externalId' => 'out-amb-1'])],
    );

    expect($batch->stats['transfers_linked'])->toBe(0)
        ->and($batch->stats['transfer_suggestions'])->toBe(2);

    expect(Transaction::where('external_id', 'out-amb-1')->first()->transfer_id)->toBeNull();
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

it('duas parcelas idênticas (mesmo número) no mesmo arquivo são compras diferentes: um plano para cada', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [
            ingestRow(['description' => 'Loja X', 'amount' => 10000, 'date' => '2026-03-03', 'installment' => ['number' => 1, 'total' => 3], 'externalId' => 'x1']),
            ingestRow(['description' => 'Loja X', 'amount' => 10000, 'date' => '2026-03-03', 'installment' => ['number' => 1, 'total' => 3], 'externalId' => 'x2']),
        ],
    );

    expect($batch->stats['inserted'])->toBe(2)
        ->and($batch->stats['replaced'])->toBe(0)
        ->and(InstallmentPlan::where('account_id', $card->id)->count())->toBe(2);

    foreach (['x1', 'x2'] as $externalId) {
        $transaction = Transaction::where('external_id', $externalId)->first();
        expect($transaction->installment_plan_id)->not->toBeNull();
    }
});

it('parcela de número maior listada antes da de número menor no arquivo: a de menor número semeia o plano e a confirmação não falha', function () {
    $this->travelTo('2026-02-01');
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [
            ingestRow(['line' => 1, 'description' => 'Notebook Exemplo', 'amount' => 35000, 'date' => '2026-04-05', 'installment' => ['number' => 3, 'total' => 10], 'externalId' => 'nb-3']),
            ingestRow(['line' => 2, 'description' => 'Notebook Exemplo', 'amount' => 35000, 'date' => '2026-03-03', 'installment' => ['number' => 2, 'total' => 10], 'externalId' => 'nb-2']),
        ],
    );

    expect(InstallmentPlan::where('account_id', $card->id)->count())->toBe(1)
        ->and($batch->stats['inserted'])->toBe(1)
        ->and($batch->stats['replaced'])->toBe(1);

    $plan = InstallmentPlan::where('account_id', $card->id)->first();
    $parcels = Transaction::where('installment_plan_id', $plan->id)->orderBy('installment_number')->get();

    expect($parcels)->toHaveCount(9) // parcelas 2..10
        ->and($parcels->first()->installment_number)->toBe(2)
        ->and($parcels->first()->external_id)->toBe('nb-2')
        ->and($parcels->get(1)->installment_number)->toBe(3)
        ->and($parcels->get(1)->external_id)->toBe('nb-3')
        ->and($parcels->get(1)->status)->toBe(TransactionStatus::Posted);
});

it('em conta de cartão, pagamento de fatura relatado pelo extrato adota a perna da transferência sem mudar os totais da fatura', function () {
    $this->travelTo('2026-02-15');
    $checking = Account::factory()->create(['user_id' => $this->user->id]);
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-17']);

    $legs = app(PayStatement::class)->handle($statement, $checking->id, Money::cents(120000), CarbonImmutable::parse('2026-03-05'));

    $before = CardStatement::query()->withTotals()->findOrFail($statement->id);
    $paidBefore = $before->paid()->cents;
    $remainingBefore = $before->remaining()->cents;

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::NubankCard]),
        [ingestRow([
            'description' => 'Pagamento recebido', 'amount' => 120000, 'direction' => Direction::In,
            'date' => '2026-03-06', 'externalId' => 'pay-1',
        ])],
    );

    expect($batch->stats['adopted'])->toBe(1)
        ->and($batch->stats['inserted'])->toBe(0);

    $legs['in']->refresh();
    expect($legs['in']->external_id)->toBe('pay-1')
        ->and($legs['in']->source)->toBe(TransactionSource::Csv)
        ->and($legs['in']->amount->cents)->toBe(120000);

    $after = CardStatement::query()->withTotals()->findOrFail($statement->id);
    expect($after->paid()->cents)->toBe($paidBefore)
        ->and($after->remaining()->cents)->toBe($remainingBefore);
});

it('meta.bill_id aponta a fatura local pelo external_id, em vez da resolução por data', function () {
    $this->travelTo('2026-02-15');
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    // Fatura já sincronizada de um ciclo bem mais adiante do que a data da
    // transação levaria pela resolução padrão (que criaria uma fatura nova
    // de abril): sem o bill_id, a transação nunca cairia aqui.
    $farStatement = CardStatement::factory()->create([
        'account_id' => $card->id,
        'closing_date' => '2026-08-03',
        'due_date' => '2026-08-10',
        'external_id' => 'bill-ext-1',
    ]);

    $statementCountBefore = CardStatement::where('account_id', $card->id)->count();

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Pluggy]),
        [ingestRow(['date' => '2026-03-07', 'externalId' => 'pl-1', 'meta' => ['bill_id' => 'bill-ext-1']])],
    );

    expect($batch->stats['inserted'])->toBe(1)
        ->and(CardStatement::where('account_id', $card->id)->count())->toBe($statementCountBefore);

    $transaction = Transaction::where('external_id', 'pl-1')->first();
    expect($transaction->statement_id)->toBe($farStatement->id);
});

it('meta.bill_id sem fatura local correspondente cai na resolução por data normal', function () {
    $this->travelTo('2026-02-15');
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    $batch = $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Pluggy]),
        [ingestRow(['date' => '2026-03-07', 'externalId' => 'pl-2', 'meta' => ['bill_id' => 'bill-que-nao-existe']])],
    );

    expect($batch->stats['inserted'])->toBe(1);

    $transaction = Transaction::where('external_id', 'pl-2')->first();
    expect($transaction->statement_id)->not->toBeNull();
});

it('categoria do provedor só entra depois de regra e histórico, e só se o usuário tiver a categoria ativa', function () {
    $categoryRule = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Categoria da regra']);
    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'mercado']],
        'actions' => [['type' => 'set_category', 'category_id' => $categoryRule->id]],
    ]);

    $categoryHistory = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Categoria do histórico']);
    Transaction::factory()->count(2)->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'description' => 'Padaria Joao', 'direction' => 'out', 'category_id' => $categoryHistory->id,
    ]);

    Category::factory()->income()->create(['user_id' => $this->user->id, 'name' => 'Salário']);

    $providerCategory = ['provider_category' => ['name' => 'Salário', 'parent' => null]];

    $this->action->handle(
        pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Pluggy]),
        [
            // Casa a regra (descrição): a regra vence mesmo com
            // meta.provider_category presente.
            ingestRow(['description' => 'Compra Mercado Exemplo', 'externalId' => 'r1', 'meta' => $providerCategory]),
            // Casa o histórico (mesma description_key e direção de "Padaria
            // Joao", direção out): o histórico vence.
            ingestRow(['description' => 'Padaria Joao 99', 'externalId' => 'r2', 'meta' => $providerCategory]),
            // Nem regra nem histórico: cai no provedor, que resolve para "Salário".
            ingestRow(['description' => 'Pix recebido', 'direction' => Direction::In, 'externalId' => 'r3', 'meta' => $providerCategory]),
        ],
    );

    $byRule = Transaction::where('external_id', 'r1')->first();
    $byHistory = Transaction::where('external_id', 'r2')->first();
    $byProvider = Transaction::where('external_id', 'r3')->first();

    expect($byRule->category_id)->toBe($categoryRule->id)
        ->and($byRule->categorized_by)->toStartWith('rule:')
        ->and($byHistory->category_id)->toBe($categoryHistory->id)
        ->and($byHistory->categorized_by)->toBe('history')
        ->and($byProvider->category_id)->toBe(Category::where('name', 'Salário')->value('id'))
        ->and($byProvider->categorized_by)->toBe('pluggy');
});

it('categoria do provedor sem sinônimo/nome conhecido ou sem categoria ativa correspondente não categoriza', function () {
    $this->action->handle(
        pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Pluggy]),
        [
            // Nome que não bate com sinônimo nem com nenhuma categoria do usuário.
            ingestRow(['externalId' => 'u1', 'direction' => Direction::In, 'meta' => ['provider_category' => ['name' => 'Categoria Desconhecida', 'parent' => null]]]),
            // Nome conhecido ("Salário"), mas o usuário não tem essa categoria.
            ingestRow(['externalId' => 'u2', 'direction' => Direction::In, 'meta' => ['provider_category' => ['name' => 'Salário', 'parent' => null]]]),
        ],
    );

    expect(Transaction::where('external_id', 'u1')->first()->category_id)->toBeNull()
        ->and(Transaction::where('external_id', 'u2')->first()->category_id)->toBeNull();
});

it('categoria do provedor ignora categoria arquivada com o mesmo nome', function () {
    Category::factory()->income()->create(['user_id' => $this->user->id, 'name' => 'Salário', 'is_archived' => true]);

    $this->action->handle(
        pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Pluggy]),
        [ingestRow(['externalId' => 'arq-1', 'direction' => Direction::In, 'meta' => ['provider_category' => ['name' => 'Salário', 'parent' => null]]])],
    );

    expect(Transaction::where('external_id', 'arq-1')->first()->category_id)->toBeNull();
});

it('categoria do provedor nunca escolhe uma categoria is_transfer sem um sinônimo explícito', function () {
    Category::factory()->transfer()->create(['user_id' => $this->user->id, 'name' => 'Transferências']);

    $this->action->handle(
        pendingBatch(['account_id' => $this->account->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Pluggy]),
        // Nome exato "Transferências", mas sem vir de um sinônimo de
        // transferência (ex.: "pagamento de cartão", "mesma titularidade").
        [ingestRow(['externalId' => 'transf-1', 'meta' => ['provider_category' => ['name' => 'Transferências', 'parent' => null]]])],
    );

    expect(Transaction::where('external_id', 'transf-1')->first()->category_id)->toBeNull();
});

it('meta.bill_id de outra fatura/conta com o mesmo external_id nunca é usado (preload escopado por conta)', function () {
    $this->travelTo('2026-02-15');
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $otherCard = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    // Mesmo external_id em duas faturas de cartões diferentes: o preload de
    // IngestTransactions precisa escopar por account_id, senão a fatura do
    // cartão errado poderia "roubar" a transação do cartão certo.
    CardStatement::factory()->create([
        'account_id' => $otherCard->id, 'closing_date' => '2026-08-03', 'due_date' => '2026-08-10', 'external_id' => 'shared-bill',
    ]);
    $ownStatement = CardStatement::factory()->create([
        'account_id' => $card->id, 'closing_date' => '2026-08-03', 'due_date' => '2026-08-10', 'external_id' => 'shared-bill',
    ]);

    $this->action->handle(
        pendingBatch(['account_id' => $card->id, 'user_id' => $this->user->id, 'format' => ImportFormat::Pluggy]),
        [ingestRow(['date' => '2026-03-07', 'externalId' => 'pl-shared', 'meta' => ['bill_id' => 'shared-bill']])],
    );

    $transaction = Transaction::where('external_id', 'pl-shared')->first();
    expect($transaction->statement_id)->toBe($ownStatement->id);
});
