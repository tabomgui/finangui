<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Enums\StatementStatus;
use App\Domain\Cards\Models\CardStatement;
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
