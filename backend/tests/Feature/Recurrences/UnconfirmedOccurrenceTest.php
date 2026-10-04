<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Imports\Actions\IngestTransactions;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Recurrences\Actions\DeleteRecurrence;
use App\Domain\Recurrences\Actions\UpdateRecurrence;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

/**
 * Prevista de recorrência adotada por uma linha de banco pendente datada no
 * futuro: ParsedRow::status() resolve isso como Projected (não Pending), já
 * que ainda não aconteceu de verdade — mas, uma vez adotada, ganha
 * external_id e deixa de ser uma ocorrência "livre"
 * (Transaction::isUnconfirmedOccurrence() passa a dar false mesmo com
 * status ainda projected).
 *
 * @return array{0: Transaction, 1: Recurrence}
 */
function adoptedFutureOccurrence(): array
{
    CarbonImmutable::setTestNow('2026-03-01');

    $recurrence = Recurrence::factory()->create([
        'account_id' => test()->account->id, 'user_id' => test()->user->id,
        'description' => 'Aluguel', 'amount' => 150000, 'direction' => Direction::Out,
        'starts_on' => '2026-03-05', 'day_of_month' => 5,
    ]);

    $occurrence = Transaction::factory()->create([
        'account_id' => test()->account->id, 'user_id' => test()->user->id,
        'status' => 'projected', 'source' => 'recurrence',
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-03-05', 'date' => '2026-03-05',
        'description' => 'Aluguel', 'original_description' => 'Aluguel',
        'amount' => 150000, 'direction' => Direction::Out,
    ]);

    $row = new ParsedRow(
        line: 1, date: '2026-03-07', amount: 150000, direction: Direction::Out,
        description: 'Aluguel', externalId: 'bank-1', installment: null, pending: true,
    );
    $batch = ImportBatch::factory()->create([
        'account_id' => test()->account->id, 'user_id' => test()->user->id, 'format' => ImportFormat::Nubank,
    ]);
    app(IngestTransactions::class)->handle($batch, [$row]);

    $occurrence->refresh();

    expect($occurrence->status->value)->toBe('projected')
        ->and($occurrence->external_id)->toBe('bank-1')
        ->and($occurrence->isUnconfirmedOccurrence())->toBeFalse();

    return [$occurrence, $recurrence];
}

it('pausar a recorrência não toca na ocorrência já adotada pelo banco', function () {
    [$occurrence, $recurrence] = adoptedFutureOccurrence();

    app(UpdateRecurrence::class)->handle($recurrence, ['is_active' => false]);

    $occurrence->refresh();
    expect($occurrence->exists)->toBeTrue()
        ->and($occurrence->status->value)->toBe('projected')
        ->and($occurrence->external_id)->toBe('bank-1')
        ->and($occurrence->date->toDateString())->toBe('2026-03-07')
        ->and($occurrence->recurrence_id)->toBe($recurrence->id);
});

it('editar a recorrência (campo simples) não sobrescreve a ocorrência já adotada pelo banco', function () {
    [$occurrence, $recurrence] = adoptedFutureOccurrence();

    app(UpdateRecurrence::class)->handle($recurrence, ['description' => 'Aluguel novo']);

    $occurrence->refresh();
    expect($occurrence->description)->toBe('Aluguel')
        ->and($occurrence->external_id)->toBe('bank-1');
});

it('mudar o calendário da recorrência não exclui a ocorrência já adotada pelo banco', function () {
    [$occurrence, $recurrence] = adoptedFutureOccurrence();

    app(UpdateRecurrence::class)->handle($recurrence, ['day_of_month' => 20]);

    $occurrence->refresh();
    expect($occurrence->exists)->toBeTrue()
        ->and($occurrence->external_id)->toBe('bank-1')
        ->and($occurrence->recurrence_id)->toBe($recurrence->id);
});

it('excluir a recorrência mantém a ocorrência já adotada pelo banco, só desliga recurrence_id', function () {
    [$occurrence, $recurrence] = adoptedFutureOccurrence();

    app(DeleteRecurrence::class)->handle($recurrence);

    $occurrence->refresh();
    expect($occurrence->exists)->toBeTrue()
        ->and($occurrence->status->value)->toBe('projected')
        ->and($occurrence->external_id)->toBe('bank-1')
        ->and($occurrence->amount->cents)->toBe(150000)
        ->and($occurrence->recurrence_id)->toBeNull()
        ->and($occurrence->recurrence_date)->toBeNull();
});
