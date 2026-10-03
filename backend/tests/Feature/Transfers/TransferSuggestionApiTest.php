<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Models\TransferSuggestion;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
    $this->user = actingAsUser();
    $this->checking = Account::factory()->create(['user_id' => $this->user->id]);
    $this->savings = Account::factory()->create(['user_id' => $this->user->id]);
});

function suggestionLeg(Account $account, Direction $direction, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'account_id' => $account->id,
        'direction' => $direction,
        'amount' => 20000,
        'date' => '2026-09-30',
        'description' => 'Transferência comum',
    ], $overrides));
}

it('lista sugestões pendentes, mais recentes primeiro, com as duas transações', function () {
    $out1 = suggestionLeg($this->checking, Direction::Out);
    $in1 = suggestionLeg($this->savings, Direction::In);
    $older = TransferSuggestion::factory()->create([
        'user_id' => $this->user->id, 'out_transaction_id' => $out1->id, 'in_transaction_id' => $in1->id, 'score' => 0.6,
    ]);

    $this->travelTo(now()->addMinute());
    $out2 = suggestionLeg($this->checking, Direction::Out);
    $in2 = suggestionLeg($this->savings, Direction::In);
    $newer = TransferSuggestion::factory()->create([
        'user_id' => $this->user->id, 'out_transaction_id' => $out2->id, 'in_transaction_id' => $in2->id, 'score' => 0.8,
    ]);

    TransferSuggestion::factory()->create([
        'user_id' => $this->user->id, 'status' => TransferSuggestionStatus::Dismissed, 'score' => 0,
    ]);

    $response = $this->getJson('/api/v1/transfer-suggestions')->assertOk()->json('data');

    expect($response)->toHaveCount(2)
        ->and($response[0]['id'])->toBe($newer->id)
        ->and($response[0]['score'])->toBe(0.8)
        ->and($response[0]['out']['id'])->toBe($out2->id)
        ->and($response[0]['in']['id'])->toBe($in2->id)
        ->and($response[0]['out']['account']['id'])->toBe($this->checking->id)
        ->and($response[1]['id'])->toBe($older->id);
});

it('pagina por cursor: per_page limita a página e devolve o cursor da próxima', function () {
    foreach (range(1, 3) as $i) {
        $out = suggestionLeg($this->checking, Direction::Out, ['amount' => 1000 * $i]);
        $in = suggestionLeg($this->savings, Direction::In, ['amount' => 1000 * $i]);
        TransferSuggestion::factory()->create([
            'user_id' => $this->user->id, 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id, 'score' => 0.6,
        ]);
    }

    $first = $this->getJson('/api/v1/transfer-suggestions?per_page=2')->assertOk()->json();

    expect($first['data'])->toHaveCount(2)
        ->and($first['meta']['per_page'])->toBe(2)
        ->and($first['links']['next'])->not->toBeNull();

    $second = $this->getJson($first['links']['next'])->assertOk()->json('data');
    expect($second)->toHaveCount(1);
});

it('detect liga pares inequívocos e sugere os ambíguos, respeitando o throttle próprio', function () {
    $out = suggestionLeg($this->checking, Direction::Out);
    $in = suggestionLeg($this->savings, Direction::In);

    $response = $this->postJson('/api/v1/transfer-suggestions/detect')->assertOk()->json('data');

    expect($response)->toBe(['linked' => 1, 'suggested' => 0]);
    expect($out->refresh()->transfer_id)->not->toBeNull();
});

it('detect usa um contador de throttle próprio, isolado dos outros limites', function () {
    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/v1/transfer-suggestions/detect')->assertOk();
    }

    $this->postJson('/api/v1/transfer-suggestions/detect')->assertStatus(429);
});

it('aceita uma sugestão: liga o par e devolve a transferência', function () {
    $out = suggestionLeg($this->checking, Direction::Out);
    $in = suggestionLeg($this->savings, Direction::In);
    $suggestion = TransferSuggestion::factory()->create([
        'user_id' => $this->user->id, 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id, 'score' => 0.6,
    ]);

    $this->postJson("/api/v1/transfer-suggestions/{$suggestion->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.from.id', $out->id)
        ->assertJsonPath('data.to.id', $in->id);

    expect($out->refresh()->transfer_id)->not->toBeNull()
        ->and($in->refresh()->transfer_id)->toBe($out->transfer_id)
        ->and(TransferSuggestion::query()->whereKey($suggestion->id)->exists())->toBeFalse();
});

it('aceitar uma sugestão não pendente dá 409', function () {
    $out = suggestionLeg($this->checking, Direction::Out);
    $in = suggestionLeg($this->savings, Direction::In);
    $suggestion = TransferSuggestion::factory()->create([
        'user_id' => $this->user->id, 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id,
        'status' => TransferSuggestionStatus::Dismissed, 'score' => 0,
    ]);

    $this->postJson("/api/v1/transfer-suggestions/{$suggestion->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_suggestion_not_pending');
});

it('aceitar uma sugestão cuja perna já está ligada (stale) dá 409', function () {
    $out = suggestionLeg($this->checking, Direction::Out);
    $in = suggestionLeg($this->savings, Direction::In);
    $suggestion = TransferSuggestion::factory()->create([
        'user_id' => $this->user->id, 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id, 'score' => 0.6,
    ]);

    // Simula uma sugestão desatualizada: a perna de saída já foi ligada a
    // outra transação por um caminho qualquer (ex.: corrida entre duas
    // detecções), mas esta sugestão específica não chegou a ser excluída.
    $out->update(['transfer_id' => (string) Str::uuid()]);

    $this->postJson("/api/v1/transfer-suggestions/{$suggestion->id}/accept")
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_link_invalid');

    // Sugestão desatualizada: exclui em vez de ficar presa pra sempre
    // (não dá pra aceitá-la de novo, e LinkTransfer só a excluiria se
    // tivesse realmente ligado).
    expect(TransferSuggestion::query()->whereKey($suggestion->id)->exists())->toBeFalse();
});

it('descarta uma sugestão: some da listagem e não reaparece com uma nova detecção', function () {
    $out = suggestionLeg($this->checking, Direction::Out);
    $in = suggestionLeg($this->savings, Direction::In);
    $suggestion = TransferSuggestion::factory()->create([
        'user_id' => $this->user->id, 'out_transaction_id' => $out->id, 'in_transaction_id' => $in->id, 'score' => 0.6,
    ]);

    $this->postJson("/api/v1/transfer-suggestions/{$suggestion->id}/dismiss")->assertNoContent();

    expect(TransferSuggestion::query()->whereKey($suggestion->id)->first()->status)->toBe(TransferSuggestionStatus::Dismissed);
    $this->getJson('/api/v1/transfer-suggestions')->assertOk()->assertJsonCount(0, 'data');

    $this->postJson('/api/v1/transfer-suggestions/detect')->assertOk();
    expect($out->refresh()->transfer_id)->toBeNull();
});

it('descartar uma sugestão não pendente dá 409', function () {
    $suggestion = TransferSuggestion::factory()->create([
        'user_id' => $this->user->id, 'status' => TransferSuggestionStatus::Dismissed, 'score' => 0,
    ]);

    $this->postJson("/api/v1/transfer-suggestions/{$suggestion->id}/dismiss")
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_suggestion_not_pending');
});

it('isola sugestões por usuário', function () {
    $suggestion = TransferSuggestion::factory()->create(['user_id' => $this->user->id, 'score' => 0.6]);

    actingAsUser();

    $this->getJson('/api/v1/transfer-suggestions')->assertOk()->assertJsonCount(0, 'data');
    $this->postJson("/api/v1/transfer-suggestions/{$suggestion->id}/accept")->assertNotFound();
    $this->postJson("/api/v1/transfer-suggestions/{$suggestion->id}/dismiss")->assertNotFound();
});
