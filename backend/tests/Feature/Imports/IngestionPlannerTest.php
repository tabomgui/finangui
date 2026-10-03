<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Imports\Support\IngestionPlanner;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

function importRow(array $overrides = []): ParsedRow
{
    return new ParsedRow(
        line: $overrides['line'] ?? 1,
        date: $overrides['date'] ?? '2026-03-07',
        amount: $overrides['amount'] ?? 1000,
        direction: $overrides['direction'] ?? Direction::Out,
        description: $overrides['description'] ?? 'Compra',
        externalId: $overrides['externalId'] ?? ('h:'.Str::random(12)),
        installment: $overrides['installment'] ?? null,
        pending: $overrides['pending'] ?? false,
    );
}

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->planner = new IngestionPlanner;
});

it('decide tudo como novo numa conta vazia', function () {
    $decisions = $this->planner->plan($this->account, [
        importRow(['line' => 1, 'externalId' => 'a']),
        importRow(['line' => 2, 'externalId' => 'b']),
    ]);

    expect(array_map(fn ($d) => $d->outcome, $decisions))->toBe([RowOutcome::New, RowOutcome::New])
        ->and(array_map(fn ($d) => $d->transactionId, $decisions))->toBe([null, null]);
});

it('external_id já existente vira duplicate, e update quando pending vira posted', function () {
    $duplicate = Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'dup-1', 'status' => 'posted',
    ]);
    $pendingExisting = Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'pend-1', 'status' => 'pending',
    ]);

    [$d1, $d2] = $this->planner->plan($this->account, [
        importRow(['line' => 1, 'externalId' => 'dup-1']),
        importRow(['line' => 2, 'externalId' => 'pend-1', 'pending' => false]),
    ]);

    expect($d1->outcome)->toBe(RowOutcome::Duplicate)->and($d1->transactionId)->toBe($duplicate->id)
        ->and($d2->outcome)->toBe(RowOutcome::Update)->and($d2->transactionId)->toBe($pendingExisting->id);
});

it('duplicada que já está posted permanece duplicate mesmo que a linha também venha pending', function () {
    $pendingExisting = Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'pend-2', 'status' => 'pending',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['externalId' => 'pend-2', 'pending' => true]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Duplicate)
        ->and($decisions[0]->transactionId)->toBe($pendingExisting->id);
});

it('duas linhas com o mesmo external_id no arquivo: a segunda vira duplicate sem transação associada', function () {
    [$d1, $d2] = $this->planner->plan($this->account, [
        importRow(['line' => 1, 'externalId' => 'same']),
        importRow(['line' => 2, 'externalId' => 'same']),
    ]);

    expect($d1->outcome)->toBe(RowOutcome::New)
        ->and($d2->outcome)->toBe(RowOutcome::Duplicate)
        ->and($d2->transactionId)->toBeNull();
});

it('adota lançamento manual sem external_id com valor igual e descrição parecida dentro da janela de datas', function () {
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt)
        ->and($decisions[0]->transactionId)->toBe($manual->id);
});

it('não adota quando a data está fora da janela de -5/+3 dias', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-14']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('não adota quando o valor é diferente', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4599, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('não adota quando a direção é diferente', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'direction' => Direction::In, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('manual que já tem external_id não é adotado de novo', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05', 'external_id' => 'already-synced',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('duas linhas iguais e um só manual: uma adota, a outra vira nova', function () {
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    [$d1, $d2] = $this->planner->plan($this->account, [
        importRow(['line' => 1, 'externalId' => 'x1', 'description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
        importRow(['line' => 2, 'externalId' => 'x2', 'description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($d1->outcome)->toBe(RowOutcome::Adopt)->and($d1->transactionId)->toBe($manual->id)
        ->and($d2->outcome)->toBe(RowOutcome::New);
});

it('empate de adoção é resolvido pela data mais próxima', function () {
    $closer = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-06',
    ]);
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-09',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt)
        ->and($decisions[0]->transactionId)->toBe($closer->id);
});

it('perna de transferência manual pode ser adotada', function () {
    $leg = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Transferência', 'original_description' => 'Transferência',
        'amount' => 5000, 'direction' => Direction::Out, 'date' => '2026-03-05', 'transfer_id' => (string) Str::uuid(),
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'TRANSFERENCIA ENVIADA', 'amount' => 5000, 'date' => '2026-03-06']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt)
        ->and($decisions[0]->transactionId)->toBe($leg->id);
});

it('troca o id de uma pending quando a linha posted bate em data/valor/direção e descrição parecida', function () {
    $pending = Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'A', 'status' => 'pending',
        'description' => 'Compra Mercado', 'original_description' => 'Compra Mercado',
        'amount' => 3000, 'direction' => Direction::Out, 'date' => '2026-03-10',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['externalId' => 'B', 'description' => 'COMPRA MERCADO EXTRA', 'amount' => 3000, 'date' => '2026-03-10']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::SwapPending)
        ->and($decisions[0]->transactionId)->toBe($pending->id);
});

it('substitui a parcela projetada quando total, número, valor e descrição batem', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Notebook Exemplo', 'installments' => 10,
    ]);
    $projected = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'projected', 'installment_plan_id' => $plan->id,
        'installment_number' => 2, 'amount' => 35000, 'direction' => Direction::Out,
        'description' => 'Notebook Exemplo', 'original_description' => 'Notebook Exemplo', 'date' => '2026-04-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'Notebook Exemplo', 'amount' => 35000, 'date' => '2026-04-07',
            'installment' => ['number' => 2, 'total' => 10],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::ReplaceInstallment)
        ->and($decisions[0]->transactionId)->toBe($projected->id);
});

it('não substitui quando o total de parcelas é diferente, e vira nova', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Notebook Exemplo', 'installments' => 10,
    ]);
    Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'projected', 'installment_plan_id' => $plan->id,
        'installment_number' => 2, 'amount' => 35000, 'direction' => Direction::Out,
        'description' => 'Notebook Exemplo', 'original_description' => 'Notebook Exemplo', 'date' => '2026-04-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'Notebook Exemplo', 'amount' => 35000, 'date' => '2026-04-07',
            'installment' => ['number' => 2, 'total' => 9],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('não consulta transações de outra conta nem de outro usuário', function () {
    $otherAccount = Account::factory()->create(['user_id' => $this->user->id]);
    Transaction::factory()->create([
        'account_id' => $otherAccount->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 1000, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    $otherUser = User::factory()->create();
    Transaction::query()->withoutGlobalScopes()->create([
        'user_id' => $otherUser->id, 'account_id' => $this->account->id, 'date' => '2026-03-05',
        'amount' => 1000, 'direction' => 'out', 'currency' => 'BRL',
        'description' => 'Mercado', 'original_description' => 'Mercado',
        'status' => 'posted', 'source' => 'manual',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 1000, 'date' => '2026-03-06']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});
