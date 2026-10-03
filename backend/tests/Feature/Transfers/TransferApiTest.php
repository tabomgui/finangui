<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\LinkTransfer;

function createTransfer(Account $from, Account $to, array $overrides = []): array
{
    return test()->postJson('/api/v1/transfers', array_merge([
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'date' => '2026-10-01',
        'amount' => 50000,
        'description' => 'Reserva',
    ], $overrides))->assertCreated()->json('data');
}

it('cria as duas pernas ligadas pelo mesmo transfer_id', function () {
    actingAsUser();
    $inter = Account::factory()->create();
    $nubank = Account::factory()->create();

    $transfer = createTransfer($inter, $nubank);

    expect($transfer['amount'])->toBe(50000)
        ->and($transfer['from']['account_id'])->toBe($inter->id)
        ->and($transfer['from']['direction'])->toBe('out')
        ->and($transfer['to']['account_id'])->toBe($nubank->id)
        ->and($transfer['to']['direction'])->toBe('in')
        ->and($transfer['from']['transfer_id'])->toBe($transfer['transfer_id'])
        ->and($transfer['to']['transfer_id'])->toBe($transfer['transfer_id'])
        ->and(Transaction::count())->toBe(2);
});

it('recusa transferência para a mesma conta', function () {
    actingAsUser();
    $inter = Account::factory()->create();

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $inter->id, 'to_account_id' => $inter->id, 'date' => '2026-10-01', 'amount' => 100, 'description' => 'x',
    ])->assertStatus(422)->assertJsonValidationErrors('to_account_id');
});

it('recusa amount acima do limite máximo', function () {
    actingAsUser();
    $inter = Account::factory()->create();
    $nubank = Account::factory()->create();

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $inter->id, 'to_account_id' => $nubank->id, 'date' => '2026-10-01',
        'amount' => 1_000_000_000_000_001, 'description' => 'x',
    ])->assertStatus(422)->assertJsonValidationErrors('amount');
});

it('recusa transferência entre moedas diferentes', function () {
    actingAsUser();
    $brl = Account::factory()->create(['currency' => 'BRL']);
    $usd = Account::factory()->create(['currency' => 'USD']);

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $brl->id, 'to_account_id' => $usd->id, 'date' => '2026-10-01', 'amount' => 100, 'description' => 'x',
    ])->assertStatus(409)->assertJsonPath('code', 'transfer_currency_mismatch');
});

it('edita valor e data nas duas pernas', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->patchJson("/api/v1/transfers/{$transfer['transfer_id']}", ['amount' => 75000, 'date' => '2026-10-02'])
        ->assertOk()
        ->assertJsonPath('data.amount', 75000)
        ->assertJsonPath('data.date', '2026-10-02');

    expect(Transaction::pluck('amount')->map->cents->all())->toBe([75000, 75000]);
});

it('troca a conta de origem', function () {
    actingAsUser();
    $to = Account::factory()->create();
    $transfer = createTransfer(Account::factory()->create(), $to);
    $newFrom = Account::factory()->create();

    $this->patchJson("/api/v1/transfers/{$transfer['transfer_id']}", ['from_account_id' => $newFrom->id])
        ->assertOk()
        ->assertJsonPath('data.from.account_id', $newFrom->id);
});

it('recusa edição que deixa origem e destino iguais', function () {
    actingAsUser();
    $to = Account::factory()->create();
    $transfer = createTransfer(Account::factory()->create(), $to);

    $this->patchJson("/api/v1/transfers/{$transfer['transfer_id']}", ['from_account_id' => $to->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_same_account');
});

it('exclui as duas pernas', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->deleteJson("/api/v1/transfers/{$transfer['transfer_id']}")->assertNoContent();

    expect(Transaction::count())->toBe(0);
});

it('excluir uma perna pelo endpoint de transações exclui as duas', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->deleteJson("/api/v1/transactions/{$transfer['from']['id']}")->assertNoContent();

    expect(Transaction::count())->toBe(0);
});

it('excluir a perna manual de um par cuja outra perna veio do banco preserva a perna do banco (desligada)', function () {
    actingAsUser();
    $checking = Account::factory()->create();
    $savings = Account::factory()->create();
    $manual = Transaction::factory()->create(['account_id' => $checking->id, 'direction' => Direction::Out, 'amount' => 10000, 'date' => '2026-10-01']);
    $fromBank = Transaction::factory()->create(['account_id' => $savings->id, 'direction' => Direction::In, 'amount' => 10000, 'date' => '2026-10-01', 'external_id' => 'ext-bank-1']);
    app(LinkTransfer::class)->handle($manual, $fromBank);

    $this->deleteJson("/api/v1/transactions/{$manual->id}")->assertNoContent();

    expect(Transaction::query()->whereKey($manual->id)->exists())->toBeFalse()
        ->and(Transaction::query()->whereKey($fromBank->id)->exists())->toBeTrue()
        ->and($fromBank->refresh()->transfer_id)->toBeNull();
});

it('excluir a perna do banco de um par cuja outra perna é manual (sem external_id) exclui as duas, como antes', function () {
    actingAsUser();
    $checking = Account::factory()->create();
    $savings = Account::factory()->create();
    $manual = Transaction::factory()->create(['account_id' => $checking->id, 'direction' => Direction::Out, 'amount' => 10000, 'date' => '2026-10-01']);
    $fromBank = Transaction::factory()->create(['account_id' => $savings->id, 'direction' => Direction::In, 'amount' => 10000, 'date' => '2026-10-01', 'external_id' => 'ext-bank-2']);
    app(LinkTransfer::class)->handle($manual, $fromBank);

    $this->deleteJson("/api/v1/transactions/{$fromBank->id}")->assertNoContent();

    expect(Transaction::count())->toBe(0);
});

it('bloqueia mudar valor de uma perna pelo endpoint de transações', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->patchJson("/api/v1/transactions/{$transfer['from']['id']}", ['amount' => 1])
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_leg_locked');

    $this->patchJson("/api/v1/transactions/{$transfer['from']['id']}", ['notes' => 'ok'])->assertOk();
});

it('retorna 404 para transferência inexistente', function () {
    actingAsUser();

    $this->patchJson('/api/v1/transfers/9b2f3c1e-0000-4000-8000-000000000000', ['amount' => 1])->assertNotFound();
});

it('retorna as duas pernas ao buscar a transferência', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->getJson("/api/v1/transfers/{$transfer['transfer_id']}")
        ->assertOk()
        ->assertJsonPath('data.transfer_id', $transfer['transfer_id'])
        ->assertJsonPath('data.from.direction', 'out')
        ->assertJsonPath('data.to.direction', 'in');
});

it('recusa edição que move uma perna para conta de outra moeda', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(['currency' => 'BRL']), Account::factory()->create(['currency' => 'BRL']));
    $usd = Account::factory()->create(['currency' => 'USD']);

    $this->patchJson("/api/v1/transfers/{$transfer['transfer_id']}", ['from_account_id' => $usd->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_currency_mismatch');
});

it('liga duas transações existentes, aceitando os ids em qualquer ordem', function () {
    actingAsUser();
    $checking = Account::factory()->create();
    $savings = Account::factory()->create();
    $out = Transaction::factory()->create(['account_id' => $checking->id, 'direction' => Direction::Out, 'amount' => 10000, 'date' => '2026-10-01']);
    $in = Transaction::factory()->create(['account_id' => $savings->id, 'direction' => Direction::In, 'amount' => 10000, 'date' => '2026-10-01']);

    // Propositalmente invertido: out_transaction_id recebe a entrada e
    // vice-versa — a action identifica a direção de verdade de cada uma.
    $response = $this->postJson('/api/v1/transfers/link', [
        'out_transaction_id' => $in->id, 'in_transaction_id' => $out->id,
    ])->assertCreated()->json('data');

    expect($response['from']['id'])->toBe($out->id)
        ->and($response['to']['id'])->toBe($in->id)
        ->and($out->refresh()->transfer_id)->toBe($response['transfer_id'])
        ->and($in->refresh()->transfer_id)->toBe($response['transfer_id']);
});

it('recusa ligar duas transações com a mesma direção', function () {
    actingAsUser();
    $checking = Account::factory()->create();
    $savings = Account::factory()->create();
    $out1 = Transaction::factory()->create(['account_id' => $checking->id, 'direction' => Direction::Out, 'amount' => 10000]);
    $out2 = Transaction::factory()->create(['account_id' => $savings->id, 'direction' => Direction::Out, 'amount' => 10000]);

    $this->postJson('/api/v1/transfers/link', [
        'out_transaction_id' => $out1->id, 'in_transaction_id' => $out2->id,
    ])->assertStatus(409)->assertJsonPath('code', 'transfer_link_invalid');
});

it('juntar à mão aceita até 7 dias de diferença, além dos 2 da detecção automática', function () {
    actingAsUser();
    $checking = Account::factory()->create();
    $savings = Account::factory()->create();
    $out = Transaction::factory()->create(['account_id' => $checking->id, 'direction' => Direction::Out, 'amount' => 10000, 'date' => '2026-10-01']);
    $in = Transaction::factory()->create(['account_id' => $savings->id, 'direction' => Direction::In, 'amount' => 10000, 'date' => '2026-10-06']);

    $this->postJson('/api/v1/transfers/link', [
        'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id,
    ])->assertCreated();
});

it('recusa link com id de transação de outro usuário', function () {
    actingAsUser();
    $foreign = Transaction::factory()->create();

    actingAsUser();
    $own = Transaction::factory()->create(['direction' => Direction::In]);

    $this->postJson('/api/v1/transfers/link', [
        'out_transaction_id' => $foreign->id, 'in_transaction_id' => $own->id,
    ])->assertStatus(422)->assertJsonValidationErrors('out_transaction_id');
});

it('desliga uma transferência: as duas pernas voltam a ser lançamentos comuns', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    $this->postJson("/api/v1/transfers/{$transfer['transfer_id']}/unlink")->assertNoContent();

    expect(Transaction::whereKey($transfer['from']['id'])->first()->transfer_id)->toBeNull()
        ->and(Transaction::whereKey($transfer['to']['id'])->first()->transfer_id)->toBeNull();
});

it('isola transferências por usuário', function () {
    actingAsUser();
    $transfer = createTransfer(Account::factory()->create(), Account::factory()->create());

    actingAsUser();

    $this->getJson("/api/v1/transfers/{$transfer['transfer_id']}")->assertNotFound();
    $this->patchJson("/api/v1/transfers/{$transfer['transfer_id']}", ['amount' => 1])->assertNotFound();
    $this->deleteJson("/api/v1/transfers/{$transfer['transfer_id']}")->assertNotFound();
});
