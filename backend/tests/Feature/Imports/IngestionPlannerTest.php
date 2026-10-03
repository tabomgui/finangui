<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Data\RowDecision;
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

/**
 * @param  list<RowDecision>  $decisions
 */
function decisionFor(array $decisions, string $externalId): RowDecision
{
    foreach ($decisions as $decision) {
        if ($decision->row->externalId === $externalId) {
            return $decision;
        }
    }

    throw new RuntimeException("Nenhuma decisão para external_id {$externalId}");
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

it('existente pending com linha também pending permanece duplicate, não vira update', function () {
    $pendingExisting = Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'pend-2', 'status' => 'pending',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['externalId' => 'pend-2', 'pending' => true]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Duplicate)
        ->and($decisions[0]->transactionId)->toBe($pendingExisting->id);
});

it('existente posted com linha pending também vira duplicate, não update', function () {
    $posted = Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'posted-1', 'status' => 'posted',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['externalId' => 'posted-1', 'pending' => true]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Duplicate)
        ->and($decisions[0]->transactionId)->toBe($posted->id);
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

it('adota no limite de 5 dias (banco lança depois do manual)', function () {
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-01',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-06']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt)->and($decisions[0]->transactionId)->toBe($manual->id);
});

it('não adota passando 1 dia do limite de 5 (6 dias depois)', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-01',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('adota no limite de 3 dias antes (banco lança antes do manual)', function () {
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-10',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt)->and($decisions[0]->transactionId)->toBe($manual->id);
});

it('não adota passando 1 dia do limite de 3 antes (4 dias antes)', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-11',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
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

it('manual ignorado continua elegível para adoção', function () {
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05', 'is_ignored' => true,
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt)->and($decisions[0]->transactionId)->toBe($manual->id);
});

it('adota pela original_description quando a description atual não é parecida o suficiente', function () {
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Loja Exemplo', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt)->and($decisions[0]->transactionId)->toBe($manual->id);
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

it('empate de adoção é resolvido pela maior similaridade antes do id', function () {
    $lessSimilar = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado Extra Padaria', 'original_description' => 'Mercado Extra Padaria',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);
    $moreSimilar = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado Extra', 'original_description' => 'Mercado Extra',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-09',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA CARTAO MERCADO EXTRA', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt)
        ->and($decisions[0]->transactionId)->toBe($moreSimilar->id)
        ->and($lessSimilar->id)->not->toBe($moreSimilar->id);
});

it('empate de adoção (mesma distância e mesma similaridade) é resolvido pelo menor id', function () {
    $lowerId = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado Extra', 'original_description' => 'Mercado Extra',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);
    $higherId = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado Extra', 'original_description' => 'Mercado Extra',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-09',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'COMPRA CARTAO MERCADO EXTRA', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($lowerId->id)->toBeLessThan($higherId->id)
        ->and($decisions[0]->outcome)->toBe(RowOutcome::Adopt)
        ->and($decisions[0]->transactionId)->toBe($lowerId->id);
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

it('substitui a parcela nº1 de uma compra manual (posted) quando total, número, valor e descrição batem', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'installments' => 5, 'purchase_date' => '2026-03-05',
    ]);
    $parcel = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 1, 'amount' => 20000, 'direction' => Direction::Out,
        'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico', 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA ELETRONICO LOJA', 'amount' => 20000, 'date' => '2026-03-07',
            'installment' => ['number' => 1, 'total' => 5],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::ReplaceInstallment)
        ->and($decisions[0]->transactionId)->toBe($parcel->id);
});

it('substitui parcela intermediária já lançada pelo job diário (status posted, não só projected)', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'installments' => 5, 'purchase_date' => '2026-01-05',
    ]);
    $parcel = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 3, 'amount' => 15000, 'direction' => Direction::Out,
        'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico', 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA ELETRONICO LOJA', 'amount' => 15002, 'date' => '2026-03-07',
            'installment' => ['number' => 3, 'total' => 5],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::ReplaceInstallment)
        ->and($decisions[0]->transactionId)->toBe($parcel->id);
});

it('tolera até installments-1 centavos de diferença (5 centavos numa parcela nº7 de 7x)', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'installments' => 7, 'purchase_date' => '2025-09-05',
    ]);
    $parcel = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 7, 'amount' => 10000, 'direction' => Direction::Out,
        'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico', 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA ELETRONICO LOJA', 'amount' => 10005, 'date' => '2026-03-07',
            'installment' => ['number' => 7, 'total' => 7],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::ReplaceInstallment)
        ->and($decisions[0]->transactionId)->toBe($parcel->id);
});

it('a tolerância é pelo total de parcelas do plano, não pelo número da parcela (nº1 de 7x com 5 centavos de diferença substitui)', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'installments' => 7, 'purchase_date' => '2026-03-05',
    ]);
    $parcel = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 1, 'amount' => 10000, 'direction' => Direction::Out,
        'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico', 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA ELETRONICO LOJA', 'amount' => 10005, 'date' => '2026-03-07',
            'installment' => ['number' => 1, 'total' => 7],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::ReplaceInstallment)
        ->and($decisions[0]->transactionId)->toBe($parcel->id);
});

it('não substitui uma parcela que já tem external_id, e vira nova', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'installments' => 5, 'purchase_date' => '2026-03-05',
    ]);
    Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 1, 'amount' => 20000, 'direction' => Direction::Out,
        'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico', 'date' => '2026-03-05',
        'external_id' => 'already-linked',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA ELETRONICO LOJA', 'amount' => 20000, 'date' => '2026-03-07',
            'installment' => ['number' => 1, 'total' => 5],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
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

it('um plano antigo com o mesmo nome fora da janela de datas não bate, e vira nova', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'installments' => 5, 'purchase_date' => '2025-01-05',
    ]);
    Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 1, 'amount' => 20000, 'direction' => Direction::Out,
        'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico', 'date' => '2025-01-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA ELETRONICO LOJA', 'amount' => 20000, 'date' => '2026-03-07',
            'installment' => ['number' => 1, 'total' => 5],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('substitui quando a parcela está longe mas a data da compra do plano está perto da linha', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'installments' => 5, 'purchase_date' => '2026-03-04',
    ]);
    $parcel = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 1, 'amount' => 20000, 'direction' => Direction::Out,
        'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico', 'date' => '2026-06-01',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA ELETRONICO LOJA', 'amount' => 20000, 'date' => '2026-03-07',
            'installment' => ['number' => 1, 'total' => 5],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::ReplaceInstallment)
        ->and($decisions[0]->transactionId)->toBe($parcel->id);
});

it('não substitui quando a direção é diferente (estorno não bate parcela de saída)', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'installments' => 5, 'purchase_date' => '2026-03-05',
    ]);
    Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 1, 'amount' => 20000, 'direction' => Direction::Out,
        'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico', 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA ELETRONICO LOJA', 'amount' => 20000, 'direction' => Direction::In, 'date' => '2026-03-07',
            'installment' => ['number' => 1, 'total' => 5],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('não substitui quando a descrição do plano não é parecida com a da linha', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Sapato Elegante', 'installments' => 5, 'purchase_date' => '2026-03-05',
    ]);
    Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 1, 'amount' => 20000, 'direction' => Direction::Out,
        'description' => 'Sapato Elegante', 'original_description' => 'Sapato Elegante', 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 20000, 'date' => '2026-03-07',
            'installment' => ['number' => 1, 'total' => 5],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('linha com installment numa conta que não é cartão não tenta substituir parcela, e vira nova', function () {
    $decisions = $this->planner->plan($this->account, [
        importRow(['description' => 'Compra Eletronico', 'amount' => 20000, 'installment' => ['number' => 1, 'total' => 5]]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('replace_installment tem prioridade sobre adoção quando ambas casariam', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $plan = InstallmentPlan::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'installments' => 5, 'purchase_date' => '2026-03-05',
    ]);
    $parcel = Transaction::factory()->create([
        'account_id' => $card->id, 'status' => 'posted', 'installment_plan_id' => $plan->id,
        'installment_number' => 1, 'amount' => 20000, 'direction' => Direction::Out,
        'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico', 'date' => '2026-03-05',
    ]);
    $manual = Transaction::factory()->create([
        'account_id' => $card->id, 'description' => 'Compra Eletronico', 'original_description' => 'Compra Eletronico',
        'amount' => 20000, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);

    $decisions = $this->planner->plan($card, [
        importRow([
            'description' => 'COMPRA ELETRONICO LOJA', 'amount' => 20000, 'date' => '2026-03-07',
            'installment' => ['number' => 1, 'total' => 5],
        ]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::ReplaceInstallment)
        ->and($decisions[0]->transactionId)->toBe($parcel->id)
        ->and($decisions[0]->transactionId)->not->toBe($manual->id);
});

it('adoção tem prioridade sobre troca de pending quando ambas casariam', function () {
    $manual = Transaction::factory()->create([
        'account_id' => $this->account->id, 'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-05',
    ]);
    $pending = Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'other-id', 'status' => 'pending',
        'description' => 'Mercado', 'original_description' => 'Mercado',
        'amount' => 4590, 'direction' => Direction::Out, 'date' => '2026-03-07',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['externalId' => 'row-id', 'description' => 'COMPRA MERCADO EXEMPLO', 'amount' => 4590, 'date' => '2026-03-07']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::Adopt)
        ->and($decisions[0]->transactionId)->toBe($manual->id)
        ->and($decisions[0]->transactionId)->not->toBe($pending->id);
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

it('não troca quando a linha também está pending', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'A', 'status' => 'pending',
        'description' => 'Compra Mercado', 'original_description' => 'Compra Mercado',
        'amount' => 3000, 'direction' => Direction::Out, 'date' => '2026-03-10',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['externalId' => 'B', 'description' => 'COMPRA MERCADO EXTRA', 'amount' => 3000, 'date' => '2026-03-10', 'pending' => true]),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('não troca quando a similaridade fica abaixo de 0.7', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'A', 'status' => 'pending',
        'description' => 'Compra Mercado', 'original_description' => 'Compra Mercado',
        'amount' => 3000, 'direction' => Direction::Out, 'date' => '2026-03-10',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['externalId' => 'B', 'description' => 'PADARIA DO JOAO', 'amount' => 3000, 'date' => '2026-03-10']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('não troca quando a data é diferente', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'A', 'status' => 'pending',
        'description' => 'Compra Mercado', 'original_description' => 'Compra Mercado',
        'amount' => 3000, 'direction' => Direction::Out, 'date' => '2026-03-10',
    ]);

    $decisions = $this->planner->plan($this->account, [
        importRow(['externalId' => 'B', 'description' => 'Compra Mercado', 'amount' => 3000, 'date' => '2026-03-11']),
    ]);

    expect($decisions[0]->outcome)->toBe(RowOutcome::New);
});

it('o id da existente pending não é reaproveitado quando o próprio arquivo já traz esse external_id (em qualquer ordem)', function () {
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'external_id' => 'A', 'status' => 'pending',
        'description' => 'Compra Mercado', 'original_description' => 'Compra Mercado',
        'amount' => 3000, 'direction' => Direction::Out, 'date' => '2026-03-10',
    ]);

    $rowA = importRow(['externalId' => 'A', 'description' => 'Compra Mercado', 'amount' => 3000, 'date' => '2026-03-10']);
    $rowB = importRow(['externalId' => 'B', 'description' => 'Compra Mercado', 'amount' => 3000, 'date' => '2026-03-10']);

    $forward = $this->planner->plan($this->account, [$rowA, $rowB]);
    expect(decisionFor($forward, 'A')->outcome)->toBe(RowOutcome::Update)
        ->and(decisionFor($forward, 'B')->outcome)->toBe(RowOutcome::New);

    $pendingAgain = Transaction::query()->where('external_id', 'A')->first();
    expect($pendingAgain)->not->toBeNull();

    $backward = $this->planner->plan($this->account, [$rowB, $rowA]);
    expect(decisionFor($backward, 'B')->outcome)->toBe(RowOutcome::New)
        ->and(decisionFor($backward, 'A')->outcome)->toBe(RowOutcome::Update);
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
