<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Recurrences\Support\RecurrenceMatcher;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->matcher = new RecurrenceMatcher;
});

/**
 * Prevista de recorrência (status projected, recurrence_id preenchido),
 * pronta para entrar no pool de RecurrenceMatcher::bestMatch() — mesmo
 * formato que IngestionPlanner::recurrencePool() e
 * CreateTransaction::matchRecurrence() carregam (relação `recurrence`
 * já carregada, para match_pattern).
 */
function occurrence(array $overrides = []): Transaction
{
    $recurrenceOverrides = $overrides['recurrence'] ?? [];
    unset($overrides['recurrence']);

    $recurrence = Recurrence::factory()->create([
        'account_id' => test()->account->id,
        'user_id' => test()->user->id,
        'description' => $overrides['description'] ?? 'Aluguel',
        'amount' => $overrides['amount'] ?? 150000,
        'direction' => $overrides['direction'] ?? Direction::Out,
        ...$recurrenceOverrides,
    ]);

    $transaction = Transaction::factory()->create([
        'account_id' => test()->account->id,
        'user_id' => test()->user->id,
        'status' => 'projected',
        'source' => 'recurrence',
        'recurrence_id' => $recurrence->id,
        'recurrence_date' => $overrides['date'] ?? '2026-03-05',
        'date' => $overrides['date'] ?? '2026-03-05',
        'description' => $overrides['description'] ?? 'Aluguel',
        'amount' => $overrides['amount'] ?? 150000,
        'direction' => $overrides['direction'] ?? Direction::Out,
    ]);

    return $transaction->load('recurrence');
}

/**
 * @param  list<Transaction>  $occurrences
 * @return Collection<int, Transaction>
 */
function pool(array $occurrences): Collection
{
    return new Collection($occurrences);
}

it('casa dentro da tolerância de 10% e da janela de datas', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 157500, direction: Direction::Out, date: '2026-03-07', description: 'Aluguel',
    );

    expect($match?->id)->toBe($prevista->id);
});

it('não casa passando de 10% de diferença no valor', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 172500, direction: Direction::Out, date: '2026-03-07', description: 'Aluguel',
    );

    expect($match)->toBeNull();
});

it('casa no limite de 5 dias com o real lançado depois da prevista (atraso)', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 150000, direction: Direction::Out, date: '2026-03-10', description: 'Aluguel',
    );

    expect($match?->id)->toBe($prevista->id);
});

it('não casa passando 1 dia do limite de 5 dias de atraso', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 150000, direction: Direction::Out, date: '2026-03-11', description: 'Aluguel',
    );

    expect($match)->toBeNull();
});

it('casa no limite de 3 dias com o real lançado antes da prevista (adiantado)', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 150000, direction: Direction::Out, date: '2026-03-02', description: 'Aluguel',
    );

    expect($match?->id)->toBe($prevista->id);
});

it('não casa passando 1 dia do limite de 3 dias adiantado', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 150000, direction: Direction::Out, date: '2026-03-01', description: 'Aluguel',
    );

    expect($match)->toBeNull();
});

it('não casa quando a direção é diferente', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05', 'direction' => Direction::Out]);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 150000, direction: Direction::In, date: '2026-03-05', description: 'Aluguel',
    );

    expect($match)->toBeNull();
});

it('sem match_pattern, casa pela sobreposição de tokens com a descrição da prevista', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05', 'description' => 'Aluguel Apartamento']);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 150000, direction: Direction::Out, date: '2026-03-05', description: 'Pagamento Aluguel Apartamento',
    );

    expect($match?->id)->toBe($prevista->id);
});

it('sem match_pattern, não casa quando a descrição real não é parecida com a da prevista', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05', 'description' => 'Aluguel Apartamento']);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 150000, direction: Direction::Out, date: '2026-03-05', description: 'Padaria Do Bairro',
    );

    expect($match)->toBeNull();
});

it('com match_pattern, casa quando a descrição real contém o padrão, mesmo sem semelhança com a descrição da prevista', function () {
    $prevista = occurrence([
        'amount' => 150000, 'date' => '2026-03-05', 'description' => 'Assinatura',
        'recurrence' => ['match_pattern' => 'Netflix'],
    ]);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 150000, direction: Direction::Out, date: '2026-03-05', description: 'NETFLIX.COM BR 1234',
    );

    expect($match?->id)->toBe($prevista->id);
});

it('com match_pattern, não casa quando a descrição real não contém o padrão', function () {
    $prevista = occurrence([
        'amount' => 150000, 'date' => '2026-03-05', 'description' => 'Assinatura',
        'recurrence' => ['match_pattern' => 'Netflix'],
    ]);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [], amount: 150000, direction: Direction::Out, date: '2026-03-05', description: 'Spotify Premium',
    );

    expect($match)->toBeNull();
});

it('não reaproveita uma prevista já usada noutra linha do mesmo lote', function () {
    $prevista = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    $match = $this->matcher->bestMatch(
        pool([$prevista]), [$prevista->id => true], amount: 150000, direction: Direction::Out, date: '2026-03-05', description: 'Aluguel',
    );

    expect($match)->toBeNull();
});

it('empate é resolvido pela data mais próxima primeiro', function () {
    $longe = occurrence(['amount' => 150000, 'date' => '2026-03-01']);
    $perto = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    $match = $this->matcher->bestMatch(
        pool([$longe, $perto]), [], amount: 150000, direction: Direction::Out, date: '2026-03-05', description: 'Aluguel',
    );

    expect($match?->id)->toBe($perto->id);
});

it('empate de data é resolvido pelo valor mais próximo em seguida', function () {
    $longeDoValor = occurrence(['amount' => 160000, 'date' => '2026-03-05']);
    $pertoDoValor = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    $match = $this->matcher->bestMatch(
        pool([$longeDoValor, $pertoDoValor]), [], amount: 150000, direction: Direction::Out, date: '2026-03-05', description: 'Aluguel',
    );

    expect($match?->id)->toBe($pertoDoValor->id);
});

it('empate de data e valor é resolvido pelo menor id por último', function () {
    $primeira = occurrence(['amount' => 150000, 'date' => '2026-03-05']);
    $segunda = occurrence(['amount' => 150000, 'date' => '2026-03-05']);

    expect($primeira->id)->toBeLessThan($segunda->id);

    $match = $this->matcher->bestMatch(
        pool([$segunda, $primeira]), [], amount: 150000, direction: Direction::Out, date: '2026-03-05', description: 'Aluguel',
    );

    expect($match?->id)->toBe($primeira->id);
});
