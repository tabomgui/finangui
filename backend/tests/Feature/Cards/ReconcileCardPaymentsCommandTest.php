<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderBillPayment;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'gui@example.com']);
});

it('reconcilia um cartão conectado buscando as faturas do banco de novo', function () {
    $this->actingAs($this->user);
    $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);
    $card = Account::factory()->creditCard()->create([
        'user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'ext-card-1',
    ]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2031-02-01', 'due_date' => '2031-02-10', 'external_id' => 'bill-fev']);
    $payment = Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => Direction::In, 'status' => TransactionStatus::Posted,
        'amount' => 5000, 'date' => '2031-02-14', 'description' => 'Pagamento recebido',
    ]);

    $provider = fakeBankProvider();
    $provider->billsByAccount['ext-card-1'] = [
        new ProviderBill(id: 'bill-fev', dueDate: '2031-02-10', closingDate: '2031-02-01', totalCents: 5000, payments: [
            new ProviderBillPayment('pay-1', '2031-02-14', 5000),
        ]),
    ];

    $this->artisan('cards:reconcile-payments')->assertSuccessful();

    $payment->refresh();
    expect($payment->card_payment_statement_id)->toBe($statement->id);
});

it('reconcilia um cartão sem conexão só pelas regras que não dependem do banco', function () {
    $this->actingAs($this->user);
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2031-02-01', 'due_date' => '2031-02-10']);
    $payment = Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => Direction::In, 'status' => TransactionStatus::Posted,
        'amount' => 5000, 'date' => '2031-02-14', 'description' => 'Pagamento de fatura',
    ]);

    $this->artisan('cards:reconcile-payments')->assertSuccessful();

    $payment->refresh();
    expect($payment->card_payment_statement_id)->toBe($statement->id);
});

it('filtra por --user (id ou e-mail), sem tocar nos cartões de outro usuário', function () {
    $other = User::factory()->create();
    $this->actingAs($other);
    $otherCard = Account::factory()->creditCard()->create(['user_id' => $other->id]);
    $otherStatement = CardStatement::factory()->create(['account_id' => $otherCard->id, 'closing_date' => '2031-02-01', 'due_date' => '2031-02-10']);
    $otherPayment = Transaction::factory()->create([
        'account_id' => $otherCard->id, 'direction' => Direction::In, 'status' => TransactionStatus::Posted,
        'amount' => 5000, 'date' => '2031-02-14', 'description' => 'Pagamento de fatura',
    ]);

    $this->artisan('cards:reconcile-payments', ['--user' => 'gui@example.com'])->assertSuccessful();

    $otherPayment->refresh();
    expect($otherPayment->card_payment_statement_id)->toBeNull();
});

it('sem usuário nenhum cadastrado, falha', function () {
    User::query()->delete();

    $this->artisan('cards:reconcile-payments')->assertFailed();
});

it('imprime quantos pagamentos marcou, duplicatas ignorou e transferências ligou', function () {
    $this->actingAs($this->user);
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2031-02-01', 'due_date' => '2031-02-10']);
    Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => Direction::In, 'status' => TransactionStatus::Posted,
        'amount' => 5000, 'date' => '2031-02-14', 'description' => 'Pagamento recebido',
    ]);

    $this->artisan('cards:reconcile-payments')
        ->expectsOutputToContain('1 pagamento(s) marcado(s), 0 duplicata(s) ignorada(s), 0 transferência(s) ligada(s)')
        ->assertSuccessful();
});

it('--dry-run mostra o que mudaria sem gravar nada', function () {
    $this->actingAs($this->user);
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2031-02-01', 'due_date' => '2031-02-10']);
    $payment = Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => Direction::In, 'status' => TransactionStatus::Posted,
        'amount' => 5000, 'date' => '2031-02-14', 'description' => 'Pagamento recebido',
    ]);

    // Uma única substring (não duas): cada expectsOutputToContain() vira uma
    // expectativa própria do mock de saída, e a mesma linha só "serve" a
    // primeira que casar — duas expectativas contra pedaços da mesma linha
    // nunca seriam satisfeitas juntas.
    $this->artisan('cards:reconcile-payments', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] Cartão '.$card->id." ({$card->name}): 1 pagamento(s) marcado(s)")
        ->assertSuccessful();

    expect($payment->refresh()->card_payment_statement_id)->toBeNull();
});

it('continua para os outros cartões quando um falha ao reconciliar, e termina com código de falha', function () {
    $this->actingAs($this->user);

    $broken = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $broken->update(['closing_day' => null, 'due_day' => null]);
    Transaction::factory()->create([
        'account_id' => $broken->id, 'direction' => Direction::In, 'status' => TransactionStatus::Posted,
        'amount' => 1000, 'date' => '2031-02-14', 'description' => 'Pagamento recebido',
    ]);

    $ok = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $ok->id, 'closing_date' => '2031-02-01', 'due_date' => '2031-02-10']);
    $payment = Transaction::factory()->create([
        'account_id' => $ok->id, 'direction' => Direction::In, 'status' => TransactionStatus::Posted,
        'amount' => 5000, 'date' => '2031-02-14', 'description' => 'Pagamento recebido',
    ]);

    $this->artisan('cards:reconcile-payments')->assertFailed();

    expect($payment->refresh()->card_payment_statement_id)->toBe($statement->id);
});

it('quando a busca das faturas falha, pula o cartão (não reconcilia com dados incompletos) e termina com código de falha', function () {
    $this->actingAs($this->user);
    $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);
    $card = Account::factory()->creditCard()->create([
        'user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'ext-card-2',
    ]);
    CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2031-02-01', 'due_date' => '2031-02-10']);
    $payment = Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => Direction::In, 'status' => TransactionStatus::Posted,
        'amount' => 5000, 'date' => '2031-02-14', 'description' => 'Pagamento recebido',
    ]);

    $provider = fakeBankProvider();
    $provider->failNext(new RuntimeException('Pluggy fora do ar'));

    $this->artisan('cards:reconcile-payments')->assertFailed();

    // Nada marcado: o cartão foi pulado nesta rodada, não reconciliado às
    // escuras só pelo padrão de descrição.
    expect($payment->refresh()->card_payment_statement_id)->toBeNull();
});

it('a janela do comando nunca reseta nada anterior ao fechamento da fatura mais antiga buscada agora', function () {
    $this->actingAs($this->user);
    $connection = BankConnection::factory()->create(['user_id' => $this->user->id]);
    $card = Account::factory()->creditCard()->create([
        'user_id' => $this->user->id, 'connection_id' => $connection->id, 'external_id' => 'ext-card-3',
    ]);
    CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2031-02-01', 'due_date' => '2031-02-10']);

    // Marcada como duplicata por uma reconciliação anterior, bem antes do
    // que a fatura buscada agora cobre — sem decisão nesta passagem, mas
    // fora da janela: precisa continuar exatamente como está.
    $old = Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => Direction::In, 'status' => TransactionStatus::Posted,
        'amount' => 3000, 'date' => '2030-01-05', 'description' => 'Compra qualquer',
        'is_ignored' => true, 'ignored_reason' => 'Pagamento duplicado: já contabilizado em outro lançamento do cartão.',
    ]);

    $provider = fakeBankProvider();
    $provider->billsByAccount['ext-card-3'] = [
        new ProviderBill(id: 'bill-fev-3', dueDate: '2031-02-10', closingDate: '2031-02-01', totalCents: 0),
    ];

    $this->artisan('cards:reconcile-payments')->assertSuccessful();

    $old->refresh();
    expect($old->is_ignored)->toBeTrue()
        ->and($old->ignored_reason)->not->toBeNull();
});
