<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\SyncTransactions;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderCategory;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Providers\FakeBankProvider;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
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
    $this->fake = new FakeBankProvider;
    app()->instance(BankProvider::class, $this->fake);
    $this->action = app(SyncTransactions::class);
    $this->now = CarbonImmutable::parse('2026-10-03');
});

it('sem nenhuma transação, não cria lote', function () {
    $account = Account::factory()->create();

    $this->action->handle($account, [], $this->now);

    expect(ImportBatch::count())->toBe(0);
});

it('insere transações novas com source pluggy', function () {
    $account = Account::factory()->create();

    $this->action->handle($account, [syncProviderTransaction(['id' => 'ext-1', 'amountCents' => 7000])], $this->now);

    $transaction = Transaction::query()->where('external_id', 'ext-1')->first();
    expect($transaction)->not->toBeNull()
        ->and($transaction->source)->toBe(TransactionSource::Pluggy)
        ->and($transaction->amount->cents)->toBe(7000)
        ->and($transaction->status)->toBe(TransactionStatus::Posted);
});

it('categoriza pelo pipeline, com a categoria do provedor por último', function () {
    $category = Category::factory()->create(['name' => 'Academia']);
    $this->fake->categories = [new ProviderCategory(id: 'cat-1', name: 'Academia', parentId: null)];

    $account = Account::factory()->create();
    $this->action->handle($account, [syncProviderTransaction(['id' => 'ext-cat', 'categoryId' => 'cat-1'])], $this->now);

    $transaction = Transaction::query()->where('external_id', 'ext-cat')->first();
    expect($transaction->category_id)->toBe($category->id)
        ->and($transaction->categorized_by)->toBe('pluggy');
});

it('parcela 1/N em cartão cria o plano e projeta as demais', function () {
    $card = Account::factory()->creditCard()->create();

    $this->action->handle($card, [syncProviderTransaction([
        'id' => 'ext-parcel-1', 'date' => '2026-01-15', 'amountCents' => 10000,
        'installment' => ['number' => 1, 'total' => 3],
    ])], $this->now);

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
    ])], $this->now);

    $plan = InstallmentPlan::query()->first();
    $totalBefore = Transaction::query()->where('installment_plan_id', $plan->id)->count();

    $this->action->handle($card, [syncProviderTransaction([
        // Diferença de 1 centavo (arredondamento real do banco): dentro da
        // tolerância do casamento de parcela (abs(diff) < installments, ver
        // DedupMatchers::matchInstallment) — mais do que isso, o matcher
        // trata como transação diferente de propósito.
        'id' => 'ext-parcel-2', 'date' => '2026-02-15', 'amountCents' => 9901,
        'installment' => ['number' => 2, 'total' => 3],
    ])], $this->now);

    $parcel2 = Transaction::query()->where('installment_plan_id', $plan->id)->where('installment_number', 2)->first();
    expect($parcel2->external_id)->toBe('ext-parcel-2')
        ->and($parcel2->amount->cents)->toBe(9901)
        ->and($parcel2->source)->toBe(TransactionSource::Pluggy)
        ->and(Transaction::query()->where('installment_plan_id', $plan->id)->count())->toBe($totalBefore);
});

it('bill_id define a fatura, independente da data da transação', function () {
    $card = Account::factory()->creditCard(closingDay: 3, dueDay: 10)->create();
    $statement = CardStatement::factory()->create([
        'account_id' => $card->id, 'external_id' => 'bill-xyz',
        'closing_date' => '2026-05-03', 'due_date' => '2026-05-10',
    ]);

    $this->action->handle($card, [syncProviderTransaction([
        'id' => 'ext-bill', 'date' => '2026-01-01', 'billId' => 'bill-xyz',
    ])], $this->now);

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
    ])], $this->now);

    $pending->refresh();
    expect($pending->external_id)->toBe('new-ext')
        ->and($pending->status)->toBe(TransactionStatus::Posted)
        ->and(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);
});

it('reexecutar com os mesmos dados não duplica', function () {
    $account = Account::factory()->create();
    $row = syncProviderTransaction(['id' => 'ext-idem', 'amountCents' => 3000]);

    $this->action->handle($account, [$row], $this->now);
    $this->action->handle($account, [$row], $this->now);

    expect(Transaction::query()->where('external_id', 'ext-idem')->count())->toBe(1);
});

it('não importa transações anteriores ao piso (provider_sync_from) de uma conta vinculada', function () {
    $account = Account::factory()->create(['provider_sync_from' => '2026-02-01']);

    $this->action->handle($account, [
        syncProviderTransaction(['id' => 'before', 'date' => '2026-01-15']),
        syncProviderTransaction(['id' => 'after', 'date' => '2026-02-10']),
    ], $this->now);

    expect(Transaction::query()->where('external_id', 'before')->exists())->toBeFalse()
        ->and(Transaction::query()->where('external_id', 'after')->exists())->toBeTrue();
});

it('exclui pendente antigo ausente, mantém pendente recente ausente e pendente antigo presente', function () {
    $account = Account::factory()->create();

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

    $this->action->handle($account, [
        syncProviderTransaction(['id' => 'stale-present', 'date' => '2026-09-02', 'pending' => true]),
    ], $this->now);

    expect(Transaction::query()->whereKey($staleAbsent->id)->exists())->toBeFalse()
        ->and(Transaction::query()->whereKey($stalePresent->id)->exists())->toBeTrue()
        ->and(Transaction::query()->whereKey($recentAbsent->id)->exists())->toBeTrue();
});

it('nunca exclui parcelas projetadas (source installment), mesmo antigas e pendentes', function () {
    $account = Account::factory()->creditCard()->create();
    $plan = InstallmentPlan::factory()->create(['account_id' => $account->id]);
    $projected = Transaction::factory()->create([
        'account_id' => $account->id, 'status' => TransactionStatus::Pending, 'source' => TransactionSource::Installment,
        'installment_plan_id' => $plan->id, 'installment_number' => 2, 'date' => '2026-01-01',
    ]);

    $this->action->handle($account, [], $this->now);

    expect(Transaction::query()->whereKey($projected->id)->exists())->toBeTrue();
});
