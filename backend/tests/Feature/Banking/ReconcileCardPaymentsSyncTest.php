<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderBillPayment;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Cards\Enums\StatementStatus;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * Ponta a ponta, através de App\Domain\Banking\Jobs\SyncConnection, com
 * App\Domain\Banking\Providers\FakeBankProvider (nenhuma chamada de rede de
 * verdade) — ver fakeBankProvider() em tests/Pest.php. Cobre o cenário
 * completo: fatura anterior já fechada com um pagamento informado pelo
 * banco, dois créditos do cartão (um com metadado da fatura anterior, outro
 * com metadado da fatura atual — ambos ignorados, a reconciliação nunca usa
 * esse metadado para decidir a fatura quitada), compras da fatura atual
 * batendo com o total informado, e um débito na conta corrente para ligar.
 */
beforeEach(function () {
    $this->fake = fakeBankProvider();
    $this->user = actingAsUser();
    $this->itemId = '00000000-0000-0000-0000-0000000000f9';
});

it('reconcilia pagamentos de fatura durante o sync: deduplica, quita a fatura anterior, liga a transferência e fecha a fatura atual sem divergência', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-20'));

    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $checking = Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'conta-1']);
    $card = Account::factory()->creditCard(closingDay: 5, dueDay: 12)->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'cartao-1']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [
        providerAccount(['id' => 'conta-1', 'balanceCents' => 200000]),
        providerAccount(['id' => 'cartao-1', 'kind' => 'credit_card', 'balanceCents' => 45000, 'creditLimitCents' => 500000]),
    ];

    $this->fake->billsByAccount['cartao-1'] = [
        providerBill([
            'id' => 'fatura-anterior', 'closingDate' => '2026-04-05', 'dueDate' => '2026-04-12', 'totalCents' => 58000,
            'payments' => [new ProviderBillPayment('pagto-1', '2026-04-14', 58000)],
        ]),
        providerBill(['id' => 'fatura-atual', 'closingDate' => '2026-05-05', 'dueDate' => '2026-05-12', 'totalCents' => 45000]),
    ];

    $this->fake->transactionsByAccount['cartao-1'] = [
        // Mesmo pagamento, duas vezes — cada uma com o bill_id de uma fatura
        // diferente (nenhum dos dois é usado para decidir a fatura quitada).
        syncProviderTransaction([
            'id' => 'credito-1', 'date' => '2026-04-14', 'amountCents' => 58000, 'direction' => Direction::In,
            'description' => 'Pagamento recebido', 'billId' => 'fatura-anterior',
        ]),
        syncProviderTransaction([
            'id' => 'credito-2', 'date' => '2026-04-14', 'amountCents' => 58000, 'direction' => Direction::In,
            'description' => 'Pagto debito automatico', 'billId' => 'fatura-atual',
        ]),
        // Compras da fatura atual, somando o total informado pelo banco.
        syncProviderTransaction(['id' => 'compra-1', 'date' => '2026-04-20', 'amountCents' => 30000, 'direction' => Direction::Out, 'description' => 'Loja A']),
        syncProviderTransaction(['id' => 'compra-2', 'date' => '2026-04-25', 'amountCents' => 15000, 'direction' => Direction::Out, 'description' => 'Loja B']),
    ];

    $this->fake->transactionsByAccount['conta-1'] = [
        syncProviderTransaction(['id' => 'debito-1', 'date' => '2026-04-14', 'amountCents' => 58000, 'direction' => Direction::Out, 'description' => 'Pagamento de fatura cartao']),
    ];

    runConnectionSync($connection->id);

    $previous = CardStatement::query()->withTotals()->where('account_id', $card->id)->where('external_id', 'fatura-anterior')->firstOrFail();
    $current = CardStatement::query()->withTotals()->where('account_id', $card->id)->where('external_id', 'fatura-atual')->firstOrFail();

    expect($current->total()->cents)->toBe(45000)
        ->and($current->computedTotal()->cents)->toBe(45000)
        ->and($current->reported_total->cents)->toBe(45000)
        ->and($current->paid()->cents)->toBe(0)
        ->and($current->status(CarbonImmutable::today()))->toBe(StatementStatus::Closed);

    expect($previous->paid()->cents)->toBe(58000)
        ->and($previous->remaining()->cents)->toBe(0)
        ->and($previous->status(CarbonImmutable::today()))->toBe(StatementStatus::Paid);

    $credits = Transaction::query()->where('account_id', $card->id)->where('direction', 'in')->get()->keyBy('external_id');
    $ignored = $credits->filter(fn (Transaction $t) => $t->is_ignored)->values();
    $chosen = $credits->filter(fn (Transaction $t) => ! $t->is_ignored)->values();

    expect($ignored)->toHaveCount(1)
        ->and($ignored[0]->ignored_reason)->not->toBeNull();

    expect($chosen)->toHaveCount(1)
        ->and($chosen[0]->card_payment_statement_id)->toBe($previous->id)
        ->and($chosen[0]->transfer_id)->not->toBeNull();

    $debit = Transaction::query()->where('account_id', $checking->id)->where('external_id', 'debito-1')->firstOrFail();
    expect($debit->transfer_id)->toBe($chosen[0]->transfer_id);

    $beforeSecondSync = Transaction::query()->where('account_id', $card->id)
        ->orWhere('account_id', $checking->id)
        ->orderBy('id')
        ->get(['id', 'is_ignored', 'ignored_reason', 'card_payment_statement_id', 'statement_id', 'transfer_id'])
        ->toArray();

    runConnectionSync($connection->id);

    $afterSecondSync = Transaction::query()->where('account_id', $card->id)
        ->orWhere('account_id', $checking->id)
        ->orderBy('id')
        ->get(['id', 'is_ignored', 'ignored_reason', 'card_payment_statement_id', 'statement_id', 'transfer_id'])
        ->toArray();

    expect($afterSecondSync)->toEqual($beforeSecondSync);
});

it('um crédito comum ligado por DetectTransfers (sem reconciliação envolvida) resolve pela data, não pelo bill_id da fatura seguinte', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-20'));

    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $checking = Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'conta-2']);
    $card = Account::factory()->creditCard(closingDay: 5, dueDay: 12)->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'cartao-2']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [
        providerAccount(['id' => 'conta-2', 'balanceCents' => 100000]),
        providerAccount(['id' => 'cartao-2', 'kind' => 'credit_card', 'balanceCents' => 20000, 'creditLimitCents' => 300000]),
    ];

    // Sem payments[] em nenhuma fatura: a única via de reconhecimento aqui é
    // a perna de transferência que DetectTransfers vai formar sozinho.
    $this->fake->billsByAccount['cartao-2'] = [
        providerBill(['id' => 'fatura-anterior-2', 'closingDate' => '2026-04-05', 'dueDate' => '2026-04-12', 'totalCents' => 40000]),
        providerBill(['id' => 'fatura-atual-2', 'closingDate' => '2026-05-05', 'dueDate' => '2026-05-12', 'totalCents' => 12000]),
    ];

    $this->fake->transactionsByAccount['cartao-2'] = [
        // O crédito carrega o bill_id da fatura SEGUINTE (dado real da
        // Pluggy que nunca deve decidir a fatura quitada de um pagamento).
        syncProviderTransaction([
            'id' => 'credito-3', 'date' => '2026-04-14', 'amountCents' => 40000, 'direction' => Direction::In,
            'description' => 'Pagamento recebido', 'billId' => 'fatura-atual-2',
        ]),
        syncProviderTransaction(['id' => 'compra-3', 'date' => '2026-04-20', 'amountCents' => 12000, 'direction' => Direction::Out, 'description' => 'Loja C']),
    ];

    $this->fake->transactionsByAccount['conta-2'] = [
        syncProviderTransaction(['id' => 'debito-2', 'date' => '2026-04-14', 'amountCents' => 40000, 'direction' => Direction::Out, 'description' => 'Pagamento de fatura cartao']),
    ];

    runConnectionSync($connection->id);

    $previous = CardStatement::query()->withTotals()->where('account_id', $card->id)->where('external_id', 'fatura-anterior-2')->firstOrFail();
    $current = CardStatement::query()->withTotals()->where('account_id', $card->id)->where('external_id', 'fatura-atual-2')->firstOrFail();

    $credit = Transaction::query()->where('account_id', $card->id)->where('external_id', 'credito-3')->firstOrFail();
    $debit = Transaction::query()->where('account_id', $checking->id)->where('external_id', 'debito-2')->firstOrFail();

    expect($credit->transfer_id)->not->toBeNull()
        ->and($debit->transfer_id)->toBe($credit->transfer_id);

    expect($previous->paid()->cents)->toBe(40000)
        ->and($previous->remaining()->cents)->toBe(0)
        ->and($previous->status(CarbonImmutable::today()))->toBe(StatementStatus::Paid);

    expect($current->paid()->cents)->toBe(0);
});

it('o mesmo caso sem bill_id nenhum também resolve pela data, nunca pela fatura seguinte', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-20'));

    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    $checking = Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'conta-3']);
    $card = Account::factory()->creditCard(closingDay: 5, dueDay: 12)->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'cartao-3']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [
        providerAccount(['id' => 'conta-3', 'balanceCents' => 100000]),
        providerAccount(['id' => 'cartao-3', 'kind' => 'credit_card', 'balanceCents' => 20000, 'creditLimitCents' => 300000]),
    ];

    $this->fake->billsByAccount['cartao-3'] = [
        providerBill(['id' => 'fatura-anterior-3', 'closingDate' => '2026-04-05', 'dueDate' => '2026-04-12', 'totalCents' => 40000]),
        providerBill(['id' => 'fatura-atual-3', 'closingDate' => '2026-05-05', 'dueDate' => '2026-05-12', 'totalCents' => 12000]),
    ];

    $this->fake->transactionsByAccount['cartao-3'] = [
        // Sem billId: o banco às vezes simplesmente não informa nenhum.
        syncProviderTransaction([
            'id' => 'credito-4', 'date' => '2026-04-14', 'amountCents' => 40000, 'direction' => Direction::In,
            'description' => 'Pagamento recebido',
        ]),
        syncProviderTransaction(['id' => 'compra-4', 'date' => '2026-04-20', 'amountCents' => 12000, 'direction' => Direction::Out, 'description' => 'Loja D']),
    ];

    $this->fake->transactionsByAccount['conta-3'] = [
        syncProviderTransaction(['id' => 'debito-3', 'date' => '2026-04-14', 'amountCents' => 40000, 'direction' => Direction::Out, 'description' => 'Pagamento de fatura cartao']),
    ];

    runConnectionSync($connection->id);

    $previous = CardStatement::query()->withTotals()->where('account_id', $card->id)->where('external_id', 'fatura-anterior-3')->firstOrFail();
    $current = CardStatement::query()->withTotals()->where('account_id', $card->id)->where('external_id', 'fatura-atual-3')->firstOrFail();

    $credit = Transaction::query()->where('account_id', $card->id)->where('external_id', 'credito-4')->firstOrFail();
    $debit = Transaction::query()->where('account_id', $checking->id)->where('external_id', 'debito-3')->firstOrFail();

    expect($credit->transfer_id)->not->toBeNull()
        ->and($debit->transfer_id)->toBe($credit->transfer_id);

    expect($previous->paid()->cents)->toBe(40000)
        ->and($previous->remaining()->cents)->toBe(0)
        ->and($previous->status(CarbonImmutable::today()))->toBe(StatementStatus::Paid);

    expect($current->paid()->cents)->toBe(0);
});

it('reconcilia só depois de sincronizar TODAS as contas da conexão: a conta corrente do débito sincronizada depois do cartão ainda liga a transferência neste mesmo sync', function () {
    $this->travelTo(CarbonImmutable::parse('2026-05-20'));

    $connection = BankConnection::factory()->active()->create(['user_id' => $this->user->id, 'external_id' => $this->itemId, 'last_synced_at' => now()]);
    // Cartão criado ANTES da conta corrente (id menor) — sem reconciliar só depois do loop de
    // contas, syncAccount() reconciliaria o cartão com a conta corrente ainda sem a transação de
    // débito desta sincronização, e a transferência só ligaria no sync seguinte.
    $card = Account::factory()->creditCard(closingDay: 5, dueDay: 12)->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'cartao-5']);
    $checking = Account::factory()->create(['user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'conta-5']);

    $this->fake->items[$this->itemId] = providerItem(['id' => $this->itemId]);
    $this->fake->accountsByItem[$this->itemId] = [
        providerAccount(['id' => 'cartao-5', 'kind' => 'credit_card', 'balanceCents' => 12000, 'creditLimitCents' => 300000]),
        providerAccount(['id' => 'conta-5', 'balanceCents' => 100000]),
    ];

    $this->fake->billsByAccount['cartao-5'] = [
        providerBill(['id' => 'fatura-anterior-5', 'closingDate' => '2026-04-05', 'dueDate' => '2026-04-12', 'totalCents' => 40000]),
        providerBill(['id' => 'fatura-atual-5', 'closingDate' => '2026-05-05', 'dueDate' => '2026-05-12', 'totalCents' => 12000]),
    ];

    $this->fake->transactionsByAccount['cartao-5'] = [
        syncProviderTransaction([
            'id' => 'credito-5', 'date' => '2026-04-14', 'amountCents' => 40000, 'direction' => Direction::In,
            'description' => 'Pagamento recebido',
        ]),
        syncProviderTransaction(['id' => 'compra-5', 'date' => '2026-04-20', 'amountCents' => 12000, 'direction' => Direction::Out, 'description' => 'Loja E']),
    ];

    $this->fake->transactionsByAccount['conta-5'] = [
        syncProviderTransaction(['id' => 'debito-5', 'date' => '2026-04-14', 'amountCents' => 40000, 'direction' => Direction::Out, 'description' => 'Pagamento de fatura cartao']),
    ];

    runConnectionSync($connection->id);

    $credit = Transaction::query()->where('account_id', $card->id)->where('external_id', 'credito-5')->firstOrFail();
    $debit = Transaction::query()->where('account_id', $checking->id)->where('external_id', 'debito-5')->firstOrFail();

    expect($credit->card_payment_statement_id)->not->toBeNull()
        ->and($credit->transfer_id)->not->toBeNull()
        ->and($debit->transfer_id)->toBe($credit->transfer_id);
});
