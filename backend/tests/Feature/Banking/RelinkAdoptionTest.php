<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\CreateConnection;
use App\Domain\Banking\Actions\DisconnectConnection;
use App\Domain\Banking\Actions\LinkAccounts;
use App\Domain\Banking\Actions\SyncTransactions;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderItem;
use App\Domain\Banking\Data\ProviderTransaction;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Uma conta com histórico de outro formato (CSV/OFX) ou já desconectada de
 * um banco antes carrega lançamentos com `external_id` (do formato antigo)
 * ou, depois de desconectar, sem `external_id` nenhum (ver
 * App\Domain\Banking\Actions\DisconnectConnection). Vincular essa conta de
 * novo a um banco (o mesmo ou outro) nunca pode duplicar esse histórico: o
 * próximo sync precisa reconhecer e adotar o que já existe.
 */
function relinkItem(string $id, string $clientUserId): ProviderItem
{
    return new ProviderItem(
        id: $id, status: 'UPDATED', clientUserId: $clientUserId, lastUpdatedAt: null,
        institutionName: 'Banco Exemplo', institutionLogoUrl: null, errorMessage: null,
    );
}

function relinkProviderAccount(string $id, string $kind = 'checking'): ProviderAccount
{
    return new ProviderAccount(
        id: $id, kind: $kind, name: $kind === 'credit_card' ? 'Cartão' : 'Conta Corrente', number: '1234',
        currency: 'BRL', balanceCents: 100000,
    );
}

beforeEach(function () {
    $this->user = actingAsUser();
    $this->fake = fakeBankProvider();
});

it('vincular uma conta com histórico importado por OFX adota, em vez de duplicar, quando o banco relata o mesmo lançamento', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);
    $ofxImported = Transaction::factory()->create([
        'account_id' => $account->id, 'external_id' => 'ofx-abc', 'source' => TransactionSource::Ofx,
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05', 'description' => 'Supermercado',
        'original_description' => 'COMPRA SUPERMERCADO',
    ]);

    $itemId = 'item-relink-1';
    $this->fake->items[$itemId] = relinkItem($itemId, CreateConnection::clientUserId($this->user));
    $this->fake->accountsByItem[$itemId] = [relinkProviderAccount('acc-1')];

    $connection = app(CreateConnection::class)->handle($this->user, $itemId)['connection'];
    app(LinkAccounts::class)->handle($connection, [['external_id' => 'acc-1', 'account_id' => $account->id]]);

    $linked = Account::query()->whereKey($account->id)->first();

    $bankRow = new ProviderTransaction(
        id: 'pluggy-xyz', date: '2026-03-07', amountCents: 4590, direction: Direction::Out,
        description: 'COMPRA SUPERMERCADO', pending: false, categoryId: null, installment: null,
        purchaseDate: null, billId: null,
    );

    app(SyncTransactions::class)->handle($this->fake, $linked, [$bankRow], CarbonImmutable::now(), []);

    expect(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);

    $ofxImported->refresh();
    expect($ofxImported->external_id)->toBe('pluggy-xyz')
        ->and($ofxImported->source)->toBe(TransactionSource::Pluggy);
});

it('desconectar e vincular de novo a mesma conta não duplica o que o banco já tinha sincronizado', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id]);
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id, 'external_id' => 'bill-old']);

    $itemId = 'item-relink-2';
    $this->fake->items[$itemId] = relinkItem($itemId, CreateConnection::clientUserId($this->user));
    $this->fake->accountsByItem[$itemId] = [
        relinkProviderAccount('acc-1'),
        relinkProviderAccount('acc-card-1', 'credit_card'),
    ];

    $connection = app(CreateConnection::class)->handle($this->user, $itemId)['connection'];
    app(LinkAccounts::class)->handle($connection, [
        ['external_id' => 'acc-1', 'account_id' => $account->id],
        ['external_id' => 'acc-card-1', 'account_id' => $card->id],
    ]);

    $linked = Account::query()->whereKey($account->id)->first();

    // A conta não tinha nenhum lançamento antes do vínculo: provider_sync_from
    // fica na data de criação da conta (hoje) — a data da transação
    // sincronizada precisa ser hoje ou depois para não cair fora do piso.
    $today = CarbonImmutable::today()->toDateString();

    $firstSync = new ProviderTransaction(
        id: 'pluggy-old', date: $today, amountCents: 5000, direction: Direction::Out,
        description: 'Compra', pending: false, categoryId: null, installment: null, purchaseDate: null, billId: null,
    );
    app(SyncTransactions::class)->handle($this->fake, $linked, [$firstSync], CarbonImmutable::now(), []);

    expect(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);

    // Mantém a conta ao desconectar (DisconnectConnection nunca exclui a
    // conta — ela volta a ser manual, com o histórico intacto).
    app(DisconnectConnection::class)->handle(BankConnection::query()->whereKey($connection->id)->firstOrFail());

    $afterDisconnect = Transaction::query()->where('account_id', $account->id)->first();
    expect($afterDisconnect->external_id)->toBeNull()
        ->and($afterDisconnect->source)->toBe(TransactionSource::Pluggy);
    expect(CardStatement::query()->whereKey($statement->id)->first()->external_id)->toBeNull();

    // Reconecta a mesma conta a uma conexão nova (outra, ou a mesma
    // instituição — o item id é sempre novo no fluxo real).
    $itemId2 = 'item-relink-2b';
    $this->fake->items[$itemId2] = relinkItem($itemId2, CreateConnection::clientUserId($this->user));
    $this->fake->accountsByItem[$itemId2] = [relinkProviderAccount('acc-1b')];

    $connection2 = app(CreateConnection::class)->handle($this->user, $itemId2)['connection'];
    app(LinkAccounts::class)->handle($connection2, [
        ['external_id' => 'acc-1b', 'account_id' => $account->id],
    ]);

    $relinked = Account::query()->whereKey($account->id)->first();

    $secondSync = new ProviderTransaction(
        id: 'pluggy-new', date: $today, amountCents: 5000, direction: Direction::Out,
        description: 'Compra', pending: false, categoryId: null, installment: null, purchaseDate: null, billId: null,
    );
    app(SyncTransactions::class)->handle($this->fake, $relinked, [$secondSync], CarbonImmutable::now(), []);

    expect(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);

    $afterRelink = Transaction::query()->where('account_id', $account->id)->first();
    expect($afterRelink->external_id)->toBe('pluggy-new');
});
