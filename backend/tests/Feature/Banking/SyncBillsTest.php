<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Actions\SyncBills;
use App\Domain\Banking\Data\ProviderBill;
use App\Domain\Cards\Models\CardStatement;

beforeEach(function () {
    actingAsUser();
    $this->action = app(SyncBills::class);
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();
});

function providerBill(array $overrides = []): ProviderBill
{
    return new ProviderBill(
        id: $overrides['id'] ?? 'bill-1',
        dueDate: $overrides['dueDate'] ?? '2026-04-20',
        closingDate: array_key_exists('closingDate', $overrides) ? $overrides['closingDate'] : '2026-04-10',
        totalCents: $overrides['totalCents'] ?? 50000,
    );
}

it('cria uma fatura nova com as datas do banco', function () {
    $this->action->handle($this->card, [providerBill()]);

    $statement = CardStatement::query()->where('account_id', $this->card->id)->first();
    expect($statement->external_id)->toBe('bill-1')
        ->and($statement->closing_date->toDateString())->toBe('2026-04-10')
        ->and($statement->due_date->toDateString())->toBe('2026-04-20')
        ->and($statement->reported_total->cents)->toBe(50000);
});

it('adota uma fatura local com o mesmo vencimento, sem external_id', function () {
    $local = CardStatement::factory()->create([
        'account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20',
    ]);

    $this->action->handle($this->card, [providerBill(['totalCents' => 77700])]);

    $local->refresh();
    expect($local->external_id)->toBe('bill-1')
        ->and($local->reported_total->cents)->toBe(77700)
        ->and(CardStatement::query()->where('account_id', $this->card->id)->count())->toBe(1);
});

it('closing_date ausente usa a nominal do InvoiceCycle para o vencimento', function () {
    $this->action->handle($this->card, [providerBill(['closingDate' => null, 'dueDate' => '2026-05-20'])]);

    $statement = CardStatement::query()->where('account_id', $this->card->id)->first();
    expect($statement->closing_date->toDateString())->toBe('2026-05-10');
});

it('datas novas que quebrariam a ordem com as vizinhas não são aplicadas, mas external_id e reported_total sim', function () {
    $previous = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);
    $existing = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20', 'external_id' => 'bill-1']);
    $next = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-05-10', 'due_date' => '2026-05-20']);

    // O banco informa um fechamento que colidiria com a fatura anterior.
    $this->action->handle($this->card, [providerBill(['closingDate' => '2026-03-05', 'totalCents' => 99900])]);

    $existing->refresh();
    expect($existing->closing_date->toDateString())->toBe('2026-04-10')
        ->and($existing->due_date->toDateString())->toBe('2026-04-20')
        ->and($existing->external_id)->toBe('bill-1')
        ->and($existing->reported_total->cents)->toBe(99900);

    expect($previous->closing_date->toDateString())->toBe('2026-03-10')
        ->and($next->closing_date->toDateString())->toBe('2026-05-10');
});

it('datas que cabem entre as vizinhas são aplicadas', function () {
    $existing = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-11', 'due_date' => '2026-04-21', 'external_id' => 'bill-1']);

    $this->action->handle($this->card, [providerBill(['closingDate' => '2026-04-10', 'dueDate' => '2026-04-20'])]);

    $existing->refresh();
    expect($existing->closing_date->toDateString())->toBe('2026-04-10')
        ->and($existing->due_date->toDateString())->toBe('2026-04-20');
});

it('upsert pelo external_id em syncs seguintes só atualiza o total quando as datas não mudam', function () {
    $existing = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20', 'external_id' => 'bill-1', 'reported_total' => 10000]);

    $this->action->handle($this->card, [providerBill(['totalCents' => 20000])]);

    $existing->refresh();
    expect($existing->reported_total->cents)->toBe(20000)
        ->and($existing->closing_date->toDateString())->toBe('2026-04-10');
});
