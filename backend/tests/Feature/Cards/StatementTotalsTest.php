<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Enums\StatementStatus;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $this->statement = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-03-03', 'due_date' => '2026-03-10',
    ]);
});

function linked(array $attributes): Transaction
{
    return Transaction::factory()->create(['account_id' => test()->card->id, 'statement_id' => test()->statement->id, ...$attributes]);
}

function loaded(): CardStatement
{
    return CardStatement::query()->withTotals()->findOrFail(test()->statement->id);
}

it('total = saídas − estornos; pagamentos contam à parte; ignoradas ficam fora', function () {
    linked(['amount' => 10000, 'direction' => Direction::Out]);
    linked(['amount' => 5000, 'direction' => Direction::Out, 'status' => TransactionStatus::Projected]);
    linked(['amount' => 3000, 'direction' => Direction::In]);
    linked(['amount' => 99900, 'direction' => Direction::Out, 'is_ignored' => true]);
    linked(['amount' => 5000, 'direction' => Direction::In, 'transfer_id' => (string) Str::uuid()]);

    $statement = loaded();

    expect($statement->total()->cents)->toBe(12000)
        ->and($statement->paid()->cents)->toBe(5000)
        ->and($statement->remaining()->cents)->toBe(7000);
});

it('saída de transferência a partir do cartão (saque) entra no total', function () {
    linked(['amount' => 2000, 'direction' => Direction::Out, 'transfer_id' => (string) Str::uuid()]);

    expect(loaded()->total()->cents)->toBe(2000);
});

it('status por data e pagamento', function () {
    linked(['amount' => 10000, 'direction' => Direction::Out]);
    $before = CarbonImmutable::parse('2026-03-02');
    $after = CarbonImmutable::parse('2026-03-05');

    expect(loaded()->status($before))->toBe(StatementStatus::Open)
        ->and(loaded()->status($after))->toBe(StatementStatus::Closed);

    linked(['amount' => 4000, 'direction' => Direction::In, 'transfer_id' => (string) Str::uuid()]);
    expect(loaded()->status($after))->toBe(StatementStatus::Partial);

    linked(['amount' => 6000, 'direction' => Direction::In, 'transfer_id' => (string) Str::uuid()]);
    expect(loaded()->status($after))->toBe(StatementStatus::Paid);
});

it('fatura fechada sem gastos conta como paga', function () {
    expect(loaded()->status(CarbonImmutable::parse('2026-03-05')))->toBe(StatementStatus::Paid);
});

it('no dia do fechamento a fatura já está fechada', function () {
    linked(['amount' => 100, 'direction' => Direction::Out]);

    expect(loaded()->status(CarbonImmutable::parse('2026-03-03')))->toBe(StatementStatus::Closed);
});

it('ler total sem withTotals é erro de programação', function () {
    expect(fn () => CardStatement::query()->findOrFail($this->statement->id)->total())->toThrow(LogicException::class);
});

it('fatura fechada exclui do total uma ocorrência de recorrência ainda não confirmada', function () {
    CarbonImmutable::setTestNow('2026-03-15');
    $recurrence = Recurrence::factory()->create(['account_id' => $this->card->id, 'user_id' => $this->user->id]);
    // closing_date (2026-03-03) já passou: a fatura está fechada.
    linked([
        'amount' => 5000, 'direction' => Direction::Out, 'status' => TransactionStatus::Projected,
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-03-01', 'date' => '2026-03-01',
        'description' => 'Netflix', 'original_description' => 'Netflix',
    ]);

    expect(loaded()->total()->cents)->toBe(0);
});

it('fatura ainda aberta mantém a previsão de uma ocorrência de recorrência ainda não confirmada', function () {
    CarbonImmutable::setTestNow('2026-03-15');
    $open = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-03', 'due_date' => '2026-04-10']);
    $recurrence = Recurrence::factory()->create(['account_id' => $this->card->id, 'user_id' => $this->user->id]);
    Transaction::factory()->create([
        'account_id' => $this->card->id, 'statement_id' => $open->id,
        'amount' => 7000, 'direction' => Direction::Out, 'status' => TransactionStatus::Projected,
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-04-05', 'date' => '2026-04-05',
        'description' => 'Netflix', 'original_description' => 'Netflix',
    ]);

    $loaded = CardStatement::query()->withTotals()->findOrFail($open->id);
    expect($loaded->total()->cents)->toBe(7000);
});

it('fatura fechada mantém no total uma ocorrência de recorrência já adotada por importação/banco', function () {
    CarbonImmutable::setTestNow('2026-03-15');
    $recurrence = Recurrence::factory()->create(['account_id' => $this->card->id, 'user_id' => $this->user->id]);
    linked([
        'amount' => 5000, 'direction' => Direction::Out, 'status' => TransactionStatus::Projected, 'external_id' => 'ext-1',
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-03-01', 'date' => '2026-04-01',
        'description' => 'Netflix', 'original_description' => 'Netflix',
    ]);

    expect(loaded()->total()->cents)->toBe(5000);
});
