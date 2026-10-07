<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\SyncTransactions;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

function syncProviderTransaction(array $overrides = []): ProviderTransaction
{
    return new ProviderTransaction(
        id: $overrides['id'] ?? 'tx-'.Str::random(8),
        date: $overrides['date'] ?? '2026-09-01',
        amountCents: $overrides['amountCents'] ?? 5000,
        direction: $overrides['direction'] ?? Direction::Out,
        description: $overrides['description'] ?? 'Compra',
        pending: $overrides['pending'] ?? false,
        categoryId: $overrides['categoryId'] ?? null,
        installment: $overrides['installment'] ?? null,
        purchaseDate: $overrides['purchaseDate'] ?? null,
        billId: $overrides['billId'] ?? null,
    );
}

beforeEach(function () {
    $this->user = actingAsUser();
    $this->fake = fakeBankProvider();
    $this->action = app(SyncTransactions::class);
    $this->now = CarbonImmutable::parse('2026-10-03');
});

it('sem nenhuma transação, não cria lote', function () {
    $account = Account::factory()->create();

    $this->action->handle($this->fake, $account, [], $this->now, []);

    expect(ImportBatch::count())->toBe(0);
});

it('insere transações novas com source pluggy', function () {
    $account = Account::factory()->create();

    $this->action->handle($this->fake, $account, [syncProviderTransaction(['id' => 'ext-1', 'amountCents' => 7000])], $this->now, []);

    $transaction = Transaction::query()->where('external_id', 'ext-1')->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->source)->toBe(TransactionSource::Pluggy)
        ->and($transaction->amount->cents)->toBe(7000)
        ->and($transaction->status)->toBe(TransactionStatus::Posted);
});

it('categoriza pelo pipeline, com a categoria do provedor por último', function () {
    $category = Category::factory()->create(['name' => 'Academia']);
    $categoriesById = ['cat-1' => new ProviderCategory(id: 'cat-1', name: 'Academia', parentId: null)];

    $account = Account::factory()->create();
    $this->action->handle($this->fake, $account, [syncProviderTransaction(['id' => 'ext-cat', 'categoryId' => 'cat-1'])], $this->now, $categoriesById);

    $transaction = Transaction::query()->where('external_id', 'ext-cat')->first();
    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->categorized_by)->toBe('pluggy');
});

it('parcela 1/N em cartão cria o plano e projeta as demais', function () {
    $card = Account::factory()->creditCard()->create();

    $this->action->handle($this->fake, $card, [syncProviderTransaction([
        'id' => 'ext-parcel-1', 'date' => '2026-01-15', 'amountCents' => 10000,
        'installment' => ['number' => 1, 'total' => 3],
    ])], $this->now, []);

    $plan = InstallmentPlan::query()->first();
    expect($plan)->not->toBeNull()
        ->and($plan->installments)->toBe(3)
        ->and(Transaction::query()->where('installment_plan_id', $plan->id)->count())->toBe(3);
});

it('parcela já existente (projetada por um plano anterior) é substituída, não duplicada', function () {
    $card = Account::factory()->creditCard()->create();

    $this->action->handle($this->fake, $card, [syncProviderTransaction([
        'id' => 'ext-seed', 'date' => '2026-01-15', 'amountCents' => 9900,
        'installment' => ['number' => 1, 'total' => 3],
    ])], $this->now, []);

    $plan = InstallmentPlan::query()->first();
    $totalBefore = Transaction::query()->where('installment_plan_id', $plan->id)->count();

    $this->action->handle($this->fake, $card, [syncProviderTransaction([
        // Diferença de 1 centavo (arredondamento real do banco): dentro da
        // tolerância do casamento de parcela (abs(diff) < installments, ver
        // DedupMatchers::matchInstallment) — mais do que isso, o matcher
        // trata como transação diferente de propósito.
        'id' => 'ext-parcel-2', 'date' => '2026-02-15', 'amountCents' => 9901,
        'installment' => ['number' => 2, 'total' => 3],
    ])], $this->now, []);

    $parcel2 = Transaction::query()->where('installment_plan_id', $plan->id)->where('installment_number', 2)->first();
    expect($parcel2->external_id)->toBe('ext-parcel-2')
        ->and($parcel2->amount->cents)->toBe(9901)
        ->and($parcel2->source)->toBe(TransactionSource::Pluggy)
        ->and(Transaction::query()->where('installment_plan_id', $plan->id)->count())->toBe($totalBefore);
});

it('parcela futura ainda pendente entra como projetada, não lançada', function () {
    $card = Account::factory()->creditCard()->create();
    $future = $this->now->addMonths(2)->toDateString();

    $this->action->handle($this->fake, $card, [syncProviderTransaction([
        'id' => 'ext-future', 'date' => $future, 'amountCents' => 5000, 'pending' => true,
    ])], $this->now, []);

    $transaction = Transaction::query()->where('external_id', 'ext-future')->first();
    expect($transaction->status)->toBe(TransactionStatus::Projected);
});

it('bill_id define a fatura, independente da data da transação', function () {
    $card = Account::factory()->creditCard(closingDay: 3, dueDay: 10)->create();
    $statement = CardStatement::factory()->create([
        'account_id' => $card->id, 'external_id' => 'bill-xyz',
        'closing_date' => '2026-05-03', 'due_date' => '2026-05-10',
    ]);

    $this->action->handle($this->fake, $card, [syncProviderTransaction([
        'id' => 'ext-bill', 'date' => '2026-01-01', 'billId' => 'bill-xyz',
    ])], $this->now, []);

    $transaction = Transaction::query()->where('external_id', 'ext-bill')->first();
    expect($transaction->statement_id)->toBe($statement->id);
});

it('pendente que vira postada com external_id novo troca o id (swap_pending), em vez de duplicar', function () {
    $account = Account::factory()->create();
    $pending = Transaction::factory()->create([
        'account_id' => $account->id, 'external_id' => 'old-ext', 'status' => TransactionStatus::Pending,
        'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
        'date' => '2026-09-01', 'description' => 'Compra',
    ]);

    $this->action->handle($this->fake, $account, [syncProviderTransaction([
        'id' => 'new-ext', 'date' => '2026-09-01', 'amountCents' => 5000, 'description' => 'Compra',
    ])], $this->now, []);

    $pending->refresh();
    expect($pending->external_id)->toBe('new-ext')
        ->and($pending->status)->toBe(TransactionStatus::Posted)
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);
});

it('reexecutar com os mesmos dados não duplica', function () {
    $account = Account::factory()->create();
    $row = syncProviderTransaction(['id' => 'ext-idem', 'amountCents' => 3000]);

    $this->action->handle($this->fake, $account, [$row], $this->now, []);
    $this->action->handle($this->fake, $account, [$row], $this->now, []);

    expect(Transaction::query()->where('external_id', 'ext-idem')->count())->toBe(1);
});

it('reexecutar com os mesmos dados (tudo duplicate) exclui o lote sem efeito', function () {
    $account = Account::factory()->create();
    $row = syncProviderTransaction(['id' => 'ext-idem-2', 'amountCents' => 3000]);

    $this->action->handle($this->fake, $account, [$row], $this->now, []);
    expect(ImportBatch::count())->toBe(1);

    $this->action->handle($this->fake, $account, [$row], $this->now, []);

    expect(ImportBatch::count())->toBe(1);
});

describe('resync: lançamento já fechado (posted) cujo banco relatou algo diferente', function () {
    it('nunca sobrescreve data ou valor de uma transação pluggy já posted, só original_description', function () {
        $account = Account::factory()->create();
        $existing = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'ext-changed', 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-09-01', 'description' => 'Compra', 'original_description' => 'Compra',
        ]);

        $this->action->handle($this->fake, $account, [syncProviderTransaction([
            'id' => 'ext-changed', 'date' => '2026-09-02', 'amountCents' => 5500, 'description' => 'Compra (ajustada)',
        ])], $this->now, []);

        $existing->refresh();
        expect($existing->amount->cents)->toBe(5000)
            ->and($existing->date->toDateString())->toBe('2026-09-01')
            ->and($existing->description)->toBe('Compra (ajustada)')
            ->and($existing->original_description)->toBe('Compra (ajustada)');
    });

    it('nunca sobrescreve a descrição quando ela foi editada pelo usuário (description_locked)', function () {
        $account = Account::factory()->create();
        $existing = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'ext-locked', 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-09-01', 'description' => 'Descrição do usuário', 'original_description' => 'Compra',
            'description_locked' => true,
        ]);

        $this->action->handle($this->fake, $account, [syncProviderTransaction([
            'id' => 'ext-locked', 'date' => '2026-09-01', 'amountCents' => 5000, 'description' => 'Descrição do banco',
        ])], $this->now, []);

        $existing->refresh();
        expect($existing->description)->toBe('Descrição do usuário')
            ->and($existing->original_description)->toBe('Descrição do banco');
    });

    it('uma descrição renomeada por regra (sem description_locked) também sobrevive, porque já difere de original_description', function () {
        $account = Account::factory()->create();
        $existing = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'ext-renamed', 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-09-01', 'description' => 'Nome da regra', 'original_description' => 'Compra Antiga',
            'description_locked' => false,
        ]);

        $this->action->handle($this->fake, $account, [syncProviderTransaction([
            'id' => 'ext-renamed', 'date' => '2026-09-01', 'amountCents' => 5000, 'description' => 'Compra Nova',
        ])], $this->now, []);

        $existing->refresh();
        expect($existing->description)->toBe('Nome da regra')
            ->and($existing->original_description)->toBe('Compra Nova');
    });

    it('uma adotada (lançamento manual confirmado) também sobrevive: description nunca iguala original_description depois de adopt()', function () {
        $account = Account::factory()->create();
        $existing = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => null, 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Manual, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-09-01', 'description' => 'Supermercado Extra', 'original_description' => 'Supermercado Extra',
        ]);

        // Primeiro sync: adoção (Adopt, não Update — a descrição do banco
        // bate o bastante com a manual para o casamento de adoção, mas não é
        // idêntica) — original_description passa a ser a do banco,
        // description nunca é tocada (ver MatchedTransactionOutcomes::adopt()).
        $this->action->handle($this->fake, $account, [syncProviderTransaction([
            'id' => 'ext-adopted', 'date' => '2026-09-01', 'amountCents' => 5000, 'description' => 'Supermercado Extra SA',
        ])], $this->now, []);

        $existing->refresh();
        expect($existing->description)->toBe('Supermercado Extra')
            ->and($existing->original_description)->toBe('Supermercado Extra SA')
            ->and($existing->external_id)->toBe('ext-adopted');

        // Segundo sync: o banco muda a descrição de novo — como description
        // já não é mais igual a original_description, continua intocada.
        $this->action->handle($this->fake, $account, [syncProviderTransaction([
            'id' => 'ext-adopted', 'date' => '2026-09-01', 'amountCents' => 5000, 'description' => 'Supermercado Extra SA (corrigido)',
        ])], $this->now, []);

        $existing->refresh();
        expect($existing->description)->toBe('Supermercado Extra')
            ->and($existing->original_description)->toBe('Supermercado Extra SA (corrigido)');
    });

    it('nunca toca a categoria de um lançamento já categorizado', function () {
        $category = Category::factory()->create();
        $account = Account::factory()->create();
        $existing = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'ext-cat-kept', 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-09-01', 'description' => 'Compra', 'original_description' => 'Compra',
            'category_id' => $category->id, 'categorized_by' => 'manual',
        ]);

        $this->action->handle($this->fake, $account, [syncProviderTransaction([
            'id' => 'ext-cat-kept', 'date' => '2026-09-01', 'amountCents' => 5000, 'description' => 'Compra (outro texto)',
        ])], $this->now, []);

        $existing->refresh();
        expect($existing->category_id)->toBe($category->id)
            ->and($existing->categorized_by)->toBe('manual');
    });

    it('reexecutar com os mesmos dados não gera update (continua duplicate)', function () {
        $account = Account::factory()->create();
        Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'ext-unchanged', 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-09-01', 'description' => 'Compra', 'original_description' => 'Compra',
        ]);

        $this->action->handle($this->fake, $account, [syncProviderTransaction([
            'id' => 'ext-unchanged', 'date' => '2026-09-01', 'amountCents' => 5000, 'description' => 'Compra',
        ])], $this->now, []);

        // Nada mudou de fato: o lote vira no-op e é descartado (mesmo
        // comportamento de "reexecutar com os mesmos dados não duplica").
        expect(ImportBatch::count())->toBe(0);
    });

    it('troca de fatura (bill_id do banco) move statement_id via AssignStatement numa compra comum', function () {
        $card = Account::factory()->creditCard(closingDay: 3, dueDay: 10)->create();
        $oldStatement = CardStatement::factory()->create([
            'account_id' => $card->id, 'external_id' => 'bill-old',
            'closing_date' => '2026-04-03', 'due_date' => '2026-04-10',
        ]);
        $newStatement = CardStatement::factory()->create([
            'account_id' => $card->id, 'external_id' => 'bill-new',
            'closing_date' => '2026-05-03', 'due_date' => '2026-05-10',
        ]);
        $existing = Transaction::factory()->create([
            'account_id' => $card->id, 'external_id' => 'ext-bill-move', 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-04-01', 'description' => 'Compra', 'original_description' => 'Compra',
            'statement_id' => $oldStatement->id,
        ]);

        $this->action->handle($this->fake, $card, [syncProviderTransaction([
            'id' => 'ext-bill-move', 'date' => '2026-04-01', 'amountCents' => 5000, 'description' => 'Compra', 'billId' => 'bill-new',
        ])], $this->now, []);

        expect($existing->refresh()->statement_id)->toBe($newStatement->id);
    });

    it('uma fatura escolhida à mão (statement_locked) nunca é movida pelo bill_id do banco', function () {
        $card = Account::factory()->creditCard(closingDay: 3, dueDay: 10)->create();
        $chosenStatement = CardStatement::factory()->create([
            'account_id' => $card->id, 'external_id' => 'bill-old',
            'closing_date' => '2026-04-03', 'due_date' => '2026-04-10',
        ]);
        CardStatement::factory()->create([
            'account_id' => $card->id, 'external_id' => 'bill-new',
            'closing_date' => '2026-05-03', 'due_date' => '2026-05-10',
        ]);
        $existing = Transaction::factory()->create([
            'account_id' => $card->id, 'external_id' => 'ext-locked-statement', 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-04-01', 'description' => 'Compra', 'original_description' => 'Compra (outra)',
            'statement_id' => $chosenStatement->id, 'statement_locked' => true,
        ]);

        $this->action->handle($this->fake, $card, [syncProviderTransaction([
            'id' => 'ext-locked-statement', 'date' => '2026-04-01', 'amountCents' => 5000, 'description' => 'Compra', 'billId' => 'bill-new',
        ])], $this->now, []);

        expect($existing->refresh()->statement_id)->toBe($chosenStatement->id);
    });

    it('um pagamento de fatura reconhecido (não travado) resincado com outro bill_id mantém a fatura; sem update de verdade', function () {
        $card = Account::factory()->creditCard(closingDay: 3, dueDay: 10)->create();
        $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-04-03', 'due_date' => '2026-04-10']);
        CardStatement::factory()->create(['account_id' => $card->id, 'external_id' => 'bill-other', 'closing_date' => '2026-05-03', 'due_date' => '2026-05-10']);
        $payment = Transaction::factory()->create([
            'account_id' => $card->id, 'external_id' => 'ext-payment', 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::In, 'amount' => 10000,
            'date' => '2026-04-05', 'description' => 'Pagamento recebido', 'original_description' => 'Pagamento recebido',
            'statement_id' => $statement->id, 'card_payment_statement_id' => $statement->id, 'card_payment_locked' => false,
        ]);

        $this->action->handle($this->fake, $card, [syncProviderTransaction([
            'id' => 'ext-payment', 'date' => '2026-04-05', 'amountCents' => 10000, 'direction' => Direction::In,
            'description' => 'Pagamento recebido', 'billId' => 'bill-other',
        ])], $this->now, []);

        expect($payment->refresh()->statement_id)->toBe($statement->id)
            ->and(ImportBatch::count())->toBe(0);
    });

    it('um pagamento travado (card_payment_locked) resincado com outro bill_id também mantém a fatura; sem update de verdade', function () {
        $card = Account::factory()->creditCard(closingDay: 3, dueDay: 10)->create();
        $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-04-03', 'due_date' => '2026-04-10']);
        CardStatement::factory()->create(['account_id' => $card->id, 'external_id' => 'bill-other-2', 'closing_date' => '2026-05-03', 'due_date' => '2026-05-10']);
        $payment = Transaction::factory()->create([
            'account_id' => $card->id, 'external_id' => 'ext-payment-locked', 'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::In, 'amount' => 10000,
            'date' => '2026-04-05', 'description' => 'Pagamento recebido', 'original_description' => 'Pagamento recebido',
            'statement_id' => $statement->id, 'card_payment_statement_id' => $statement->id, 'card_payment_locked' => true,
        ]);

        $this->action->handle($this->fake, $card, [syncProviderTransaction([
            'id' => 'ext-payment-locked', 'date' => '2026-04-05', 'amountCents' => 10000, 'direction' => Direction::In,
            'description' => 'Pagamento recebido', 'billId' => 'bill-other-2',
        ])], $this->now, []);

        expect($payment->refresh()->statement_id)->toBe($statement->id)
            ->and(ImportBatch::count())->toBe(0);
    });

    it('uma perna de transferência pendente virando posted muda o status e a data, mas nunca o valor', function () {
        $account = Account::factory()->create();
        $transfer = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'ext-transfer', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-09-01', 'description' => 'Transferência', 'original_description' => 'Transferência',
            'transfer_id' => (string) Str::uuid(),
        ]);

        $this->action->handle($this->fake, $account, [syncProviderTransaction([
            'id' => 'ext-transfer', 'date' => '2026-09-05', 'amountCents' => 7000, 'description' => 'Transferência (banco)',
        ])], $this->now, []);

        $transfer->refresh();
        expect($transfer->status)->toBe(TransactionStatus::Posted)
            ->and($transfer->amount->cents)->toBe(5000)
            ->and($transfer->date->toDateString())->toBe('2026-09-05');
    });

    it('uma parcela pendente virando posted muda o status e a data, mas nunca o valor', function () {
        $card = Account::factory()->creditCard(closingDay: 3, dueDay: 10)->create();
        $plan = InstallmentPlan::factory()->create(['account_id' => $card->id]);
        $parcel = Transaction::factory()->create([
            'account_id' => $card->id, 'external_id' => 'ext-parcel-pending', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
            'date' => '2026-09-01', 'description' => 'Parcela', 'original_description' => 'Parcela',
            'installment_plan_id' => $plan->id, 'installment_number' => 1,
        ]);

        $this->action->handle($this->fake, $card, [syncProviderTransaction([
            'id' => 'ext-parcel-pending', 'date' => '2026-09-05', 'amountCents' => 7000, 'description' => 'Parcela (banco)',
        ])], $this->now, []);

        $parcel->refresh();
        expect($parcel->status)->toBe(TransactionStatus::Posted)
            ->and($parcel->amount->cents)->toBe(5000)
            ->and($parcel->date->toDateString())->toBe('2026-09-05');
    });
});

it('exclui o lote órfão quando o ingest falha, em vez de deixar um pending preso', function () {
    $account = Account::factory()->create();

    // amount = 0 viola a constraint de banco (amount > 0): IngestTransactions::handle()
    // lança QueryException no meio da própria transação, que já desfaz o
    // que tentou gravar — mas o ImportBatch::create() de SyncTransactions
    // acontece antes disso, num commit separado, e por isso precisa ser
    // excluído manualmente no catch.
    $broken = syncProviderTransaction(['id' => 'broken', 'amountCents' => 0]);

    expect(fn () => $this->action->handle($this->fake, $account, [$broken], $this->now, []))
        ->toThrow(QueryException::class);

    expect(ImportBatch::count())->toBe(0);
});

it('não importa transações anteriores ao piso (provider_sync_from) de uma conta vinculada', function () {
    $account = Account::factory()->create(['provider_sync_from' => '2026-02-01']);

    $this->action->handle($this->fake, $account, [
        syncProviderTransaction(['id' => 'before', 'date' => '2026-01-15']),
        syncProviderTransaction(['id' => 'after', 'date' => '2026-02-10']),
    ], $this->now, []);

    expect(Transaction::query()->where('external_id', 'before')->exists())->toBeFalse()
        ->and(Transaction::query()->where('external_id', 'after')->exists())->toBeTrue();
});

describe('limpeza de pendentes antigos (listagem completa)', function () {
    it('exclui pendente antigo ausente, mantém pendente recente ausente e pendente antigo presente na listagem completa', function () {
        $account = Account::factory()->create(['external_id' => 'acc-1']);

        $staleAbsent = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'stale-absent', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'date' => '2026-09-01',
        ]);
        $stalePresent = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'stale-present', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'date' => '2026-09-02',
        ]);
        $recentAbsent = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'recent-absent', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'date' => '2026-09-30',
        ]);

        // A listagem completa (dateFrom = data do mais antigo pendente) é
        // quem decide quem "veio no sync" — não a janela normal, que nem
        // foi configurada aqui (ficaria vazia).
        $this->fake->transactionsByAccount['acc-1'] = [
            syncProviderTransaction(['id' => 'stale-present', 'date' => '2026-09-02', 'pending' => true]),
        ];

        $this->action->handle($this->fake, $account, [], $this->now, []);

        expect(Transaction::query()->whereKey($staleAbsent->id)->exists())->toBeFalse()
            ->and(Transaction::query()->whereKey($stalePresent->id)->exists())->toBeTrue()
            ->and(Transaction::query()->whereKey($recentAbsent->id)->exists())->toBeTrue();

        $call = collect($this->fake->calls)->firstWhere('method', 'transactions');
        expect($call['args']['dateFrom'])->toBe('2026-09-01')
            ->and($call['args']['createdAtFrom'])->toBeNull();
    });

    it('sem pendente antigo nenhum, não chama o provedor de novo', function () {
        $account = Account::factory()->create(['external_id' => 'acc-1']);
        Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'recent', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'date' => '2026-09-30',
        ]);

        $this->action->handle($this->fake, $account, [], $this->now, []);

        expect($this->fake->calls)->toBeEmpty();
    });

    it('sem conseguir a listagem completa, pula a limpeza sem derrubar o sync', function () {
        $account = Account::factory()->create(['external_id' => 'acc-1']);
        $staleAbsent = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'stale-absent', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'date' => '2026-09-01',
        ]);
        $this->fake->failNext(new ProviderUnavailable('fora do ar'));

        $this->action->handle($this->fake, $account, [], $this->now, []);

        expect(Transaction::query()->whereKey($staleAbsent->id)->exists())->toBeTrue();
    });

    it('nunca exclui parcelas projetadas (source installment), mesmo antigas e pendentes', function () {
        $account = Account::factory()->creditCard()->create();
        $plan = InstallmentPlan::factory()->create(['account_id' => $account->id]);
        $projected = Transaction::factory()->create([
            'account_id' => $account->id, 'status' => TransactionStatus::Pending, 'source' => TransactionSource::Installment,
            'installment_plan_id' => $plan->id, 'installment_number' => 2, 'date' => '2026-01-01',
        ]);

        $this->action->handle($this->fake, $account, [], $this->now, []);

        expect(Transaction::query()->whereKey($projected->id)->exists())->toBeTrue();
    });

    it('exclui em lote uma perna de transferência pendente antiga e ausente, mas desliga o par antes: o outro lado sobrevive sem transfer_id', function () {
        $account = Account::factory()->create(['external_id' => 'acc-1']);
        $otherAccount = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['account_id' => $account->id]);
        $transferId = (string) Str::uuid();
        $transferLeg = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'old-transfer', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'date' => '2026-09-01', 'transfer_id' => $transferId,
            'direction' => Direction::Out, 'amount' => 5000, 'import_batch_id' => $batch->id,
        ]);
        $otherLeg = Transaction::factory()->create([
            'account_id' => $otherAccount->id, 'status' => TransactionStatus::Posted,
            'date' => '2026-09-01', 'transfer_id' => $transferId,
            'direction' => Direction::In, 'amount' => 5000,
        ]);
        $this->fake->transactionsByAccount['acc-1'] = [];

        $this->action->handle($this->fake, $account, [], $this->now, []);

        expect(Transaction::query()->whereKey($transferLeg->id)->exists())->toBeFalse()
            ->and($otherLeg->refresh()->transfer_id)->toBeNull();
    });

    it('depois de desligar uma perna na limpeza, roda a detecção de novo para quem sobreviveu', function () {
        $account = Account::factory()->create(['external_id' => 'acc-1']);
        $otherAccount = Account::factory()->create();
        $thirdAccount = Account::factory()->create();
        $batch = ImportBatch::factory()->create(['account_id' => $account->id]);
        $transferId = (string) Str::uuid();

        Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'old-transfer', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'date' => '2026-09-01', 'transfer_id' => $transferId,
            'direction' => Direction::Out, 'amount' => 5000, 'import_batch_id' => $batch->id,
        ]);
        $otherLeg = Transaction::factory()->create([
            'account_id' => $otherAccount->id, 'status' => TransactionStatus::Posted,
            'date' => '2026-09-01', 'transfer_id' => $transferId,
            'direction' => Direction::In, 'amount' => 5000, 'description' => 'Transferência recebida',
        ]);
        // Candidata nova, só possível depois que otherLeg voltar a ser
        // comum: mesmo valor e data, direção oposta, outra conta.
        $newMatch = Transaction::factory()->create([
            'account_id' => $thirdAccount->id, 'status' => TransactionStatus::Posted,
            'date' => '2026-09-01', 'direction' => Direction::Out, 'amount' => 5000, 'description' => 'Transferência enviada',
        ]);
        $this->fake->transactionsByAccount['acc-1'] = [];

        $this->action->handle($this->fake, $account, [], $this->now, []);

        expect($otherLeg->refresh()->transfer_id)->not->toBeNull()
            ->and($newMatch->refresh()->transfer_id)->toBe($otherLeg->transfer_id);
    });

    it('nunca apaga uma perna de transferência adotada (não inserida por este sync): pagamento de fatura cuja perna no cartão casou com a linha do banco', function () {
        $card = Account::factory()->creditCard()->create(['external_id' => 'acc-1']);
        $checking = Account::factory()->create();
        $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-09-10', 'due_date' => '2026-09-20']);
        Transaction::factory()->create(['account_id' => $card->id, 'statement_id' => $statement->id, 'amount' => 10000]);

        $this->postJson("/api/v1/card-statements/{$statement->id}/payments", [
            'from_account_id' => $checking->id, 'amount' => 10000, 'date' => '2026-09-01',
        ])->assertCreated();

        $cardLeg = Transaction::query()->where('account_id', $card->id)->where('statement_id', $statement->id)
            ->where('direction', Direction::In)->latest('id')->first();
        $transferId = $cardLeg->transfer_id;
        expect($transferId)->not->toBeNull();

        // O banco relata a mesma linha do pagamento (mesmo valor/direção, dentro da janela de
        // DedupMatchers::matchCardPayment()): o sync principal adota a perna existente (ganha
        // external_id e source pluggy, status pending) em vez de criar outra — e
        // import_batch_id continua nulo, porque MatchedTransactionOutcomes::adopt() nunca grava
        // isso.
        $providerRow = syncProviderTransaction([
            'id' => 'ext-pay-1', 'date' => '2026-09-01', 'amountCents' => 10000,
            'direction' => Direction::In, 'description' => 'Pagamento recebido', 'pending' => true,
        ]);

        // A listagem completa da limpeza não reporta esse id de novo: pareceria "pendente
        // antiga e ausente" para a limpeza, se ela devesse mesmo considerar esta perna — ela
        // não deve, porque não foi este sync que a inseriu.
        $this->fake->transactionsByAccount['acc-1'] = [];

        $this->action->handle($this->fake, $card, [$providerRow], $this->now, []);

        $cardLeg->refresh();
        expect($cardLeg->external_id)->toBe('ext-pay-1')
            ->and($cardLeg->source)->toBe(TransactionSource::Pluggy)
            ->and($cardLeg->import_batch_id)->toBeNull()
            ->and($cardLeg->transfer_id)->toBe($transferId)
            ->and(Transaction::query()->whereKey($cardLeg->id)->exists())->toBeTrue();
    });

    it('também considera pendente futura (projected, não parcela) antiga e ausente na limpeza', function () {
        $account = Account::factory()->create(['external_id' => 'acc-1']);
        $staleProjected = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'fut-absent', 'status' => TransactionStatus::Projected,
            'source' => TransactionSource::Pluggy, 'date' => '2026-09-01',
        ]);
        $this->fake->transactionsByAccount['acc-1'] = [];

        $this->action->handle($this->fake, $account, [], $this->now, []);

        expect(Transaction::query()->whereKey($staleProjected->id)->exists())->toBeFalse();
    });
});

it('pendente futura (projected) que chega lançada pelo banco com o mesmo id vira posted, sem duplicar', function () {
    $account = Account::factory()->create();
    $projected = Transaction::factory()->create([
        'account_id' => $account->id, 'external_id' => 'fut-1', 'status' => TransactionStatus::Projected,
        'source' => TransactionSource::Pluggy, 'direction' => Direction::Out, 'amount' => 5000,
        'date' => '2026-09-01', 'description' => 'Compra',
    ]);

    $this->action->handle($this->fake, $account, [
        syncProviderTransaction(['id' => 'fut-1', 'date' => '2026-09-01', 'amountCents' => 5000, 'description' => 'Compra', 'pending' => false]),
    ], $this->now, []);

    $projected->refresh();
    expect($projected->status)->toBe(TransactionStatus::Posted)
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);
});
