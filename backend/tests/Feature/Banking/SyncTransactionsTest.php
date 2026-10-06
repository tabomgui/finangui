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

    $this->action->handle($account, [], $this->now, []);

    expect(ImportBatch::count())->toBe(0);
});

it('insere transações novas com source pluggy', function () {
    $account = Account::factory()->create();

    $this->action->handle($account, [syncProviderTransaction(['id' => 'ext-1', 'amountCents' => 7000])], $this->now, []);

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
    $this->action->handle($account, [syncProviderTransaction(['id' => 'ext-cat', 'categoryId' => 'cat-1'])], $this->now, $categoriesById);

    $transaction = Transaction::query()->where('external_id', 'ext-cat')->first();
    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->categorized_by)->toBe('pluggy');
});

it('parcela 1/N em cartão cria o plano e projeta as demais', function () {
    $card = Account::factory()->creditCard()->create();

    $this->action->handle($card, [syncProviderTransaction([
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

    $this->action->handle($card, [syncProviderTransaction([
        'id' => 'ext-seed', 'date' => '2026-01-15', 'amountCents' => 9900,
        'installment' => ['number' => 1, 'total' => 3],
    ])], $this->now, []);

    $plan = InstallmentPlan::query()->first();
    $totalBefore = Transaction::query()->where('installment_plan_id', $plan->id)->count();

    $this->action->handle($card, [syncProviderTransaction([
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

    $this->action->handle($card, [syncProviderTransaction([
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

    $this->action->handle($card, [syncProviderTransaction([
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

    $this->action->handle($account, [syncProviderTransaction([
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

    $this->action->handle($account, [$row], $this->now, []);
    $this->action->handle($account, [$row], $this->now, []);

    expect(Transaction::query()->where('external_id', 'ext-idem')->count())->toBe(1);
});

it('reexecutar com os mesmos dados (tudo duplicate) exclui o lote sem efeito', function () {
    $account = Account::factory()->create();
    $row = syncProviderTransaction(['id' => 'ext-idem-2', 'amountCents' => 3000]);

    $this->action->handle($account, [$row], $this->now, []);
    expect(ImportBatch::count())->toBe(1);

    $this->action->handle($account, [$row], $this->now, []);

    expect(ImportBatch::count())->toBe(1);
});

it('exclui o lote órfão quando o ingest falha, em vez de deixar um pending preso', function () {
    $account = Account::factory()->create();

    // amount = 0 viola a constraint de banco (amount > 0): IngestTransactions::handle()
    // lança QueryException no meio da própria transação, que já desfaz o
    // que tentou gravar — mas o ImportBatch::create() de SyncTransactions
    // acontece antes disso, num commit separado, e por isso precisa ser
    // excluído manualmente no catch.
    $broken = syncProviderTransaction(['id' => 'broken', 'amountCents' => 0]);

    expect(fn () => $this->action->handle($account, [$broken], $this->now, []))
        ->toThrow(QueryException::class);

    expect(ImportBatch::count())->toBe(0);
});

it('não importa transações anteriores ao piso (provider_sync_from) de uma conta vinculada', function () {
    $account = Account::factory()->create(['provider_sync_from' => '2026-02-01']);

    $this->action->handle($account, [
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

        $this->action->handle($account, [], $this->now, []);

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

        $this->action->handle($account, [], $this->now, []);

        expect($this->fake->calls)->toBeEmpty();
    });

    it('sem conseguir a listagem completa, pula a limpeza sem derrubar o sync', function () {
        $account = Account::factory()->create(['external_id' => 'acc-1']);
        $staleAbsent = Transaction::factory()->create([
            'account_id' => $account->id, 'external_id' => 'stale-absent', 'status' => TransactionStatus::Pending,
            'source' => TransactionSource::Pluggy, 'date' => '2026-09-01',
        ]);
        $this->fake->failNext(new ProviderUnavailable('fora do ar'));

        $this->action->handle($account, [], $this->now, []);

        expect(Transaction::query()->whereKey($staleAbsent->id)->exists())->toBeTrue();
    });

    it('nunca exclui parcelas projetadas (source installment), mesmo antigas e pendentes', function () {
        $account = Account::factory()->creditCard()->create();
        $plan = InstallmentPlan::factory()->create(['account_id' => $account->id]);
        $projected = Transaction::factory()->create([
            'account_id' => $account->id, 'status' => TransactionStatus::Pending, 'source' => TransactionSource::Installment,
            'installment_plan_id' => $plan->id, 'installment_number' => 2, 'date' => '2026-01-01',
        ]);

        $this->action->handle($account, [], $this->now, []);

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

        $this->action->handle($account, [], $this->now, []);

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

        $this->action->handle($account, [], $this->now, []);

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

        $this->action->handle($card, [$providerRow], $this->now, []);

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

        $this->action->handle($account, [], $this->now, []);

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

    $this->action->handle($account, [
        syncProviderTransaction(['id' => 'fut-1', 'date' => '2026-09-01', 'amountCents' => 5000, 'description' => 'Compra', 'pending' => false]),
    ], $this->now, []);

    $projected->refresh();
    expect($projected->status)->toBe(TransactionStatus::Posted)
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);
});
