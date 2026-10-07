<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\ReconcileCardPayments;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Banking\Data\ProviderBillPayment;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Models\TransferSuggestion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard(closingDay: 5, dueDay: 12)->create(['user_id' => $this->user->id]);
    $this->checking = Account::factory()->create(['user_id' => $this->user->id]);
    $this->action = app(ReconcileCardPayments::class);
});

function creditIn(array $overrides = []): Transaction
{
    return Transaction::factory()->create([
        'account_id' => test()->card->id,
        'direction' => Direction::In,
        'status' => TransactionStatus::Posted,
        'source' => TransactionSource::Pluggy,
        ...$overrides,
    ]);
}

function debitOut(array $overrides = []): Transaction
{
    return Transaction::factory()->create([
        'account_id' => test()->checking->id,
        'direction' => Direction::Out,
        'status' => TransactionStatus::Posted,
        'source' => TransactionSource::Pluggy,
        ...$overrides,
    ]);
}

it('cartão com crédito duplicado para o mesmo pagamento é deduplicado, quita a fatura certa e liga ao débito da conta corrente', function () {
    $previous = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10', 'external_id' => 'fatura-abril',
    ]);

    $received = creditIn(['amount' => 58000, 'date' => '2026-04-14', 'description' => 'Pagamento recebido']);
    $autoDebit = creditIn(['amount' => 58000, 'date' => '2026-04-14', 'description' => 'Pagto debito automatico']);
    $checkingDebit = debitOut(['amount' => 58000, 'date' => '2026-04-14', 'description' => 'Pagamento de fatura cartao']);

    $bill = new ProviderBill(
        id: 'fatura-abril', dueDate: '2026-04-10', closingDate: '2026-04-01', totalCents: 90000,
        payments: [new ProviderBillPayment('pagto-1', '2026-04-14', 58000)],
    );

    $counts = $this->action->handle($this->card, [$bill]);

    $received->refresh();
    $autoDebit->refresh();
    $checkingDebit->refresh();

    $ignored = $received->is_ignored ? $received : $autoDebit;
    $chosen = $received->is_ignored ? $autoDebit : $received;

    expect($ignored->is_ignored)->toBeTrue()
        ->and($ignored->ignored_reason)->not->toBeNull()
        ->and($ignored->card_payment_statement_id)->toBeNull();

    expect($chosen->is_ignored)->toBeFalse()
        ->and($chosen->card_payment_statement_id)->toBe($previous->id)
        ->and($chosen->statement_id)->toBe($previous->id)
        ->and($chosen->transfer_id)->not->toBeNull();

    expect($checkingDebit->transfer_id)->toBe($chosen->transfer_id);

    expect($counts)->toBe(['payments' => 1, 'duplicates' => 1, 'transfers_linked' => 1]);

    $statement = CardStatement::query()->withTotals()->findOrFail($previous->id);
    expect($statement->paid()->cents)->toBe(58000);
});

it('é idempotente: rodar de novo sobre o mesmo estado não muda nada e os contadores somem', function () {
    $previous = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10', 'external_id' => 'fatura-abril',
    ]);
    creditIn(['amount' => 58000, 'date' => '2026-04-14', 'description' => 'Pagamento recebido']);
    creditIn(['amount' => 58000, 'date' => '2026-04-14', 'description' => 'Pagto debito automatico']);

    $bill = new ProviderBill(
        id: 'fatura-abril', dueDate: '2026-04-10', closingDate: '2026-04-01', totalCents: 90000,
        payments: [new ProviderBillPayment('pagto-1', '2026-04-14', 58000)],
    );

    $this->action->handle($this->card, [$bill]);
    $before = Transaction::query()->where('account_id', $previous->account_id)->orderBy('id')->get(['id', 'is_ignored', 'ignored_reason', 'card_payment_statement_id', 'statement_id', 'transfer_id'])->toArray();

    $counts = $this->action->handle($this->card, [$bill]);
    $after = Transaction::query()->where('account_id', $previous->account_id)->orderBy('id')->get(['id', 'is_ignored', 'ignored_reason', 'card_payment_statement_id', 'statement_id', 'transfer_id'])->toArray();

    expect($after)->toEqual($before)
        ->and($counts)->toBe(['payments' => 0, 'duplicates' => 0, 'transfers_linked' => 0]);
});

it('estorno (entrada sem transferência, fatura nem padrão) continua abatendo o total, nunca é marcado como pagamento', function () {
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10']);
    $refund = creditIn(['amount' => 3000, 'date' => '2026-03-20', 'description' => 'Reembolso de amigo']);

    $this->action->handle($this->card, []);

    $refund->refresh();
    expect($refund->card_payment_statement_id)->toBeNull()
        ->and($refund->is_ignored)->toBeFalse();
});

it('dois pagamentos legítimos de mesmo valor em meses diferentes nunca são confundidos como duplicata', function () {
    $april = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10', 'external_id' => 'fatura-abril']);
    $may = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-05-01', 'due_date' => '2026-05-10', 'external_id' => 'fatura-maio']);

    $aprilPayment = creditIn(['amount' => 42000, 'date' => '2026-04-14', 'description' => 'Pagamento recebido']);
    $mayPayment = creditIn(['amount' => 42000, 'date' => '2026-05-14', 'description' => 'Pagamento recebido']);

    $bills = [
        new ProviderBill(id: 'fatura-abril', dueDate: '2026-04-10', closingDate: '2026-04-01', totalCents: 42000, payments: [new ProviderBillPayment('pagto-abr', '2026-04-14', 42000)]),
        new ProviderBill(id: 'fatura-maio', dueDate: '2026-05-10', closingDate: '2026-05-01', totalCents: 42000, payments: [new ProviderBillPayment('pagto-mai', '2026-05-14', 42000)]),
    ];

    $this->action->handle($this->card, $bills);

    $aprilPayment->refresh();
    $mayPayment->refresh();

    expect($aprilPayment->is_ignored)->toBeFalse()
        ->and($mayPayment->is_ignored)->toBeFalse()
        ->and($aprilPayment->card_payment_statement_id)->toBe($april->id)
        ->and($mayPayment->card_payment_statement_id)->toBe($may->id);
});

it('pagamento sem par na conta continua valendo como pagamento, mesmo sem transferência', function () {
    $previous = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10', 'external_id' => 'fatura-abril']);
    $payment = creditIn(['amount' => 20000, 'date' => '2026-04-14', 'description' => 'Pagamento recebido']);

    $bill = new ProviderBill(id: 'fatura-abril', dueDate: '2026-04-10', closingDate: '2026-04-01', totalCents: 20000, payments: [new ProviderBillPayment('pagto-1', '2026-04-14', 20000)]);

    $this->action->handle($this->card, [$bill]);

    $payment->refresh();
    expect($payment->is_ignored)->toBeFalse()
        ->and($payment->card_payment_statement_id)->toBe($previous->id)
        ->and($payment->transfer_id)->toBeNull();
});

it('reconcilia sem fatura nenhuma do banco, só pelo padrão de descrição (cartão manual, ou sem credenciais no comando)', function () {
    $previous = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10']);
    $payment = creditIn(['amount' => 15000, 'date' => '2026-04-14', 'description' => 'Pagamento de fatura']);

    $this->action->handle($this->card, []);

    $payment->refresh();
    expect($payment->card_payment_statement_id)->toBe($previous->id);
});

it('nunca move a fatura de um pagamento criado por PayStatement (source manual), mesmo quando uma fatura do banco aparece depois apontando para outra', function () {
    $chosenStatement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10']);
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-05-01', 'due_date' => '2026-05-10', 'external_id' => 'fatura-maio']);

    $payment = creditIn([
        'source' => TransactionSource::Manual,
        'amount' => 30000, 'date' => '2026-04-14', 'description' => 'Pagamento de fatura',
        'transfer_id' => (string) Str::uuid(), 'statement_id' => $chosenStatement->id, 'card_payment_statement_id' => $chosenStatement->id,
    ]);

    // Uma fatura do banco que, por coincidência de valor/data, teria um
    // "pagamento" de mesmo valor perto da data desta transferência.
    $bill = new ProviderBill(id: 'fatura-maio', dueDate: '2026-05-10', closingDate: '2026-05-01', totalCents: 30000, payments: [new ProviderBillPayment('pagto-mai', '2026-04-14', 30000)]);

    $this->action->handle($this->card, [$bill]);

    $payment->refresh();
    expect($payment->statement_id)->toBe($chosenStatement->id)
        ->and($payment->card_payment_statement_id)->toBe($chosenStatement->id);
});

it('move a fatura de uma perna de transferência vinda do banco (source pluggy) quando uma fatura do banco diz que é outra', function () {
    $wrongStatement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10']);
    $rightStatement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-05-01', 'due_date' => '2026-05-10', 'external_id' => 'fatura-maio']);

    // Um crédito vindo do banco que DetectTransfers (ou uma sugestão
    // aceita) já ligou como transferência antes de qualquer fatura do
    // banco estar disponível — a fatura ficou numa estimativa pela data.
    $payment = creditIn([
        'source' => TransactionSource::Pluggy,
        'amount' => 30000, 'date' => '2026-04-14', 'description' => 'Pagamento recebido',
        'transfer_id' => (string) Str::uuid(), 'statement_id' => $wrongStatement->id, 'card_payment_statement_id' => $wrongStatement->id,
    ]);

    $bill = new ProviderBill(id: 'fatura-maio', dueDate: '2026-05-10', closingDate: '2026-05-01', totalCents: 30000, payments: [new ProviderBillPayment('pagto-mai', '2026-04-14', 30000)]);

    $this->action->handle($this->card, [$bill]);

    $payment->refresh();
    expect($payment->statement_id)->toBe($rightStatement->id)
        ->and($payment->card_payment_statement_id)->toBe($rightStatement->id);
});

it('duas candidatas igualmente plausíveis na conta corrente: não liga sozinho, cria sugestão pendente para cada uma', function () {
    $previous = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10']);
    $payment = creditIn(['amount' => 40000, 'date' => '2026-04-14', 'description' => 'Pagamento recebido']);
    $debitA = debitOut(['amount' => 40000, 'date' => '2026-04-13', 'description' => 'Debito 1']);
    $debitB = debitOut(['amount' => 40000, 'date' => '2026-04-15', 'description' => 'Debito 2']);

    $counts = $this->action->handle($this->card, []);

    $payment->refresh();
    expect($payment->card_payment_statement_id)->toBe($previous->id)
        ->and($payment->transfer_id)->toBeNull()
        ->and($counts['transfers_linked'])->toBe(0);

    expect(TransferSuggestion::query()->where('in_transaction_id', $payment->id)->where('out_transaction_id', $debitA->id)->exists())->toBeTrue()
        ->and(TransferSuggestion::query()->where('in_transaction_id', $payment->id)->where('out_transaction_id', $debitB->id)->exists())->toBeTrue();
});

it('respeita um par já descartado pelo usuário: não liga de novo sozinho', function () {
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10']);
    $payment = creditIn(['amount' => 22000, 'date' => '2026-04-14', 'description' => 'Pagamento recebido']);
    $debit = debitOut(['amount' => 22000, 'date' => '2026-04-14', 'description' => 'Debito unico']);

    TransferSuggestion::factory()->create([
        'user_id' => $this->user->id, 'out_transaction_id' => $debit->id, 'in_transaction_id' => $payment->id,
        'status' => TransferSuggestionStatus::Dismissed,
    ]);

    $counts = $this->action->handle($this->card, []);

    $payment->refresh();
    expect($payment->transfer_id)->toBeNull()
        ->and($counts['transfers_linked'])->toBe(0);
});

it('card_payment_locked nunca é alterada pela reconciliação, mesmo sendo a âncora de um candidato igual', function () {
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10']);
    $locked = creditIn([
        'amount' => 17000, 'date' => '2026-04-14', 'description' => 'Pagto debito automatico',
        'is_ignored' => false, 'card_payment_locked' => true,
    ]);
    // Mesmo valor e data da travada: entra na combinação como referência
    // fixa (consome a vaga), então este outro crédito vira a duplicata dela
    // — mas a travada em si nunca é escrita de volta.
    $other = creditIn(['amount' => 17000, 'date' => '2026-04-14', 'description' => 'Pagamento recebido']);

    $this->action->handle($this->card, []);

    $locked->refresh();
    $other->refresh();

    expect($locked->is_ignored)->toBeFalse()
        ->and($locked->ignored_reason)->toBeNull()
        ->and($locked->card_payment_statement_id)->toBeNull();

    expect($other->is_ignored)->toBeTrue()
        ->and($other->ignored_reason)->not->toBeNull();
});

it('card_payment_locked ignorada nunca entra na combinação: um candidato igual é reconhecido normalmente', function () {
    $previous = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10']);
    creditIn([
        'amount' => 9000, 'date' => '2026-04-14', 'description' => 'Pagto debito automatico',
        'is_ignored' => true, 'card_payment_locked' => true,
    ]);
    $payment = creditIn(['amount' => 9000, 'date' => '2026-04-14', 'description' => 'Pagamento recebido']);

    $this->action->handle($this->card, []);

    expect($payment->refresh()->card_payment_statement_id)->toBe($previous->id);
});

it('dentro da janela re-sincronizada, uma transação sem decisão nesta passagem volta ao estado neutro', function () {
    $statement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-01', 'due_date' => '2026-04-10']);
    // Foi marcada como duplicata numa reconciliação anterior; a descrição não
    // bate mais com nenhum padrão (ex.: o usuário corrigiu a descrição).
    $transaction = creditIn([
        'amount' => 9000, 'date' => '2026-04-14', 'description' => 'Compra qualquer',
        'is_ignored' => true, 'ignored_reason' => ReconcileCardPayments::DUPLICATE_REASON,
    ]);

    $this->action->handle($this->card, [], CarbonImmutable::parse('2026-04-01'));

    $transaction->refresh();
    expect($transaction->is_ignored)->toBeFalse()
        ->and($transaction->ignored_reason)->toBeNull()
        ->and($transaction->card_payment_statement_id)->toBeNull();
});

it('fora da janela re-sincronizada, uma transação sem decisão nesta passagem não é tocada', function () {
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-01-01', 'due_date' => '2026-01-10']);
    $transaction = creditIn([
        'amount' => 9000, 'date' => '2026-01-05', 'description' => 'Compra qualquer',
        'is_ignored' => true, 'ignored_reason' => ReconcileCardPayments::DUPLICATE_REASON,
    ]);

    $this->action->handle($this->card, [], CarbonImmutable::parse('2026-04-01'));

    $transaction->refresh();
    expect($transaction->is_ignored)->toBeTrue()
        ->and($transaction->ignored_reason)->toBe(ReconcileCardPayments::DUPLICATE_REASON);
});

it('um pagamento sem transferência mantém a fatura escolhida pela fatura do banco quando o banco não responde no sync seguinte', function () {
    // Duas faturas fecham perto uma da outra (o usuário editou as datas) —
    // sem o pagamento do banco para desambiguar, forPayment() escolheria a
    // mais recente das duas, diferente da que a fatura do banco já indicou.
    $statementA = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-05', 'due_date' => '2026-04-12', 'external_id' => 'fatura-a']);
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-08', 'due_date' => '2026-04-15', 'external_id' => 'fatura-b']);

    $payment = creditIn(['amount' => 25000, 'date' => '2026-04-10', 'description' => 'Pagamento recebido']);

    $bill = new ProviderBill(id: 'fatura-a', dueDate: '2026-04-12', closingDate: '2026-04-05', totalCents: 25000, payments: [new ProviderBillPayment('pagto-1', '2026-04-10', 25000)]);
    $this->action->handle($this->card, [$bill]);

    $payment->refresh();
    expect($payment->card_payment_statement_id)->toBe($statementA->id);

    // Sync seguinte: a Pluggy não devolveu faturas desta vez (bills = []) —
    // o crédito continua reconhecido pelo padrão de descrição, mas sem
    // nenhum dado do banco para justificar uma mudança de fatura.
    $this->action->handle($this->card, []);

    expect($payment->refresh()->card_payment_statement_id)->toBe($statementA->id);
});
