<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Errors\NotACreditCard;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Support\StatementResolver;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = actingAsUser();
    // fecha dia 10, vence dia 20
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $this->resolver = app(StatementResolver::class);
});

function dates(CardStatement $s): array
{
    return [$s->closing_date->toDateString(), $s->due_date->toDateString()];
}

it('cria a fatura com as datas nominais na primeira compra do ciclo', function () {
    $statement = $this->resolver->forDate($this->card, CarbonImmutable::parse('2026-03-05'));

    expect($statement->exists)->toBeTrue()
        ->and(dates($statement))->toBe(['2026-03-10', '2026-03-20'])
        ->and($statement->user_id)->toBe($this->user->id);
});

it('reutiliza a fatura existente do ciclo', function () {
    $a = $this->resolver->forDate($this->card, CarbonImmutable::parse('2026-03-01'));
    $b = $this->resolver->forDate($this->card, CarbonImmutable::parse('2026-03-09'));

    expect($b->id)->toBe($a->id)->and(CardStatement::count())->toBe(1);
});

it('compra no dia do fechamento vai para a próxima fatura', function () {
    expect(dates($this->resolver->forDate($this->card, CarbonImmutable::parse('2026-03-10'))))
        ->toBe(['2026-04-10', '2026-04-20']);
});

it('respeita fechamento real antecipado (editado)', function () {
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-01-08', 'due_date' => '2026-01-18']);

    $statement = $this->resolver->forDate($this->card, CarbonImmutable::parse('2026-01-09'));

    expect(dates($statement))->toBe(['2026-02-10', '2026-02-20']);
});

it('respeita fechamento real adiado (editado)', function () {
    $jan = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-01-12', 'due_date' => '2026-01-20']);

    expect($this->resolver->forDate($this->card, CarbonImmutable::parse('2026-01-11'))->id)->toBe($jan->id);
});

it('locate não grava a fatura nova', function () {
    $statement = $this->resolver->locate($this->card, CarbonImmutable::parse('2026-03-05'));

    expect($statement->exists)->toBeFalse()
        ->and(dates($statement))->toBe(['2026-03-10', '2026-03-20'])
        ->and(CardStatement::count())->toBe(0);
});

it('fatura de pagamento é a última já fechada na data', function () {
    $feb = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-02-10', 'due_date' => '2026-02-20']);
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);

    expect($this->resolver->forPayment($this->card, CarbonImmutable::parse('2026-02-15'))->id)->toBe($feb->id);
});

it('sem fatura fechada, o pagamento vai para a fatura da data', function () {
    expect(dates($this->resolver->forPayment($this->card, CarbonImmutable::parse('2026-03-05'))))
        ->toBe(['2026-03-10', '2026-03-20']);
});

it('próxima fatura depois de uma dada', function () {
    $mar = $this->resolver->forDate($this->card, CarbonImmutable::parse('2026-03-05'));

    expect(dates($this->resolver->next($this->card, $mar)))->toBe(['2026-04-10', '2026-04-20']);
});

it('recusa conta que não é cartão', function () {
    $checking = Account::factory()->create(['user_id' => $this->user->id]);

    expect(fn () => $this->resolver->forDate($checking, CarbonImmutable::parse('2026-03-05')))->toThrow(NotACreditCard::class);
});
