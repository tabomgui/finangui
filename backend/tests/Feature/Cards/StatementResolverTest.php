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

it('revalida depois que os dias de fechamento/vencimento do cartão mudam', function () {
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-02-10', 'due_date' => '2026-02-20']);
    $this->card->update(['closing_day' => 25, 'due_day' => 5]);

    $located = $this->resolver->locate($this->card, CarbonImmutable::parse('2026-02-15'));
    expect(dates($located))->toBe(['2026-02-25', '2026-03-05']);

    $existing = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-02-25', 'due_date' => '2026-03-05']);
    $found = $this->resolver->forDate($this->card, CarbonImmutable::parse('2026-02-15'));

    expect($found->id)->toBe($existing->id)->and(CardStatement::count())->toBe(2);
});

it('fechamento adiado mais de 13 dias ainda é encontrado pela fatura seguinte', function () {
    $jan = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-01-25', 'due_date' => '2026-02-04']);

    expect($this->resolver->forDate($this->card, CarbonImmutable::parse('2026-01-20'))->id)->toBe($jan->id);
});

it('fatura distante no futuro não interfere num ciclo anterior com buraco', function () {
    $may = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-05-10', 'due_date' => '2026-05-20']);

    $mar = $this->resolver->forDate($this->card, CarbonImmutable::parse('2026-03-05'));
    expect(dates($mar))->toBe(['2026-03-10', '2026-03-20']);

    expect($this->resolver->forDate($this->card, CarbonImmutable::parse('2026-04-12'))->id)->toBe($may->id);
});

it('colisão de vencimento com fatura editada bem antes da janela não quebra o único', function () {
    CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2025-12-25', 'due_date' => '2026-01-20']);

    $statement = $this->resolver->forDate($this->card, CarbonImmutable::parse('2026-01-09'));

    expect(dates($statement))->toBe(['2026-02-10', '2026-02-20'])
        ->and(CardStatement::count())->toBe(2);
});

it('uma fatura nova nunca fecha depois (ou no mesmo dia) de uma fatura existente mais adiante', function () {
    $card = Account::factory()->creditCard(closingDay: 25, dueDay: 15)->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-02-10', 'due_date' => '2026-02-15']);

    expect($this->resolver->forDate($card, CarbonImmutable::parse('2026-01-20'))->id)->toBe($statement->id);
});

it('pagamento no próprio dia do fechamento fica ligado a essa fatura', function () {
    $statement = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);

    expect($this->resolver->forPayment($this->card, CarbonImmutable::parse('2026-03-10'))->id)->toBe($statement->id);
});

it('próxima fatura depois de uma fatura com fechamento editado', function () {
    $edited = CardStatement::factory()->create(['account_id' => $this->card->id, 'closing_date' => '2026-01-08', 'due_date' => '2026-01-18']);

    expect(dates($this->resolver->next($this->card, $edited)))->toBe(['2026-02-10', '2026-02-20']);
});

it('next() recusa fatura de outro cartão', function () {
    $otherCard = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $foreignStatement = CardStatement::factory()->create(['account_id' => $otherCard->id, 'closing_date' => '2026-01-08', 'due_date' => '2026-01-18']);

    expect(fn () => $this->resolver->next($this->card, $foreignStatement))->toThrow(LogicException::class);
});

it('isola faturas por usuário mesmo com cartões e datas coincidentes', function () {
    actingAsUser();
    $otherCard = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();
    CardStatement::factory()->create(['account_id' => $otherCard->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);

    $this->actingAs($this->user);

    $statement = $this->resolver->forDate($this->card, CarbonImmutable::parse('2026-03-05'));

    expect($statement->account_id)->toBe($this->card->id)
        ->and(dates($statement))->toBe(['2026-03-10', '2026-03-20']);
});
