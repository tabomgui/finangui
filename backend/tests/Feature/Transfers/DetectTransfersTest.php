<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\DetectTransfers;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Models\TransferSuggestion;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
    $this->user = actingAsUser();
    $this->checking = Account::factory()->create(['user_id' => $this->user->id]);
    $this->savings = Account::factory()->create(['user_id' => $this->user->id]);
    $this->detect = app(DetectTransfers::class);
});

function leg(Account $account, Direction $direction, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'account_id' => $account->id,
        'direction' => $direction,
        'amount' => 50000,
        'date' => '2026-09-30',
        // Descrição neutra (sem pista de transferência nem nome de conta):
        // a descrição default da factory é sorteada entre valores que
        // incluem "Pix recebido", o que somaria pontuação por pista e
        // quebraria o empate que estes testes dependem de ser exato.
        'description' => 'Lancamento comum',
    ], $overrides));
}

it('liga automaticamente um par inequívoco entre contas diferentes', function () {
    $out = leg($this->checking, Direction::Out);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 1, 'suggested' => 0]);

    expect($out->refresh()->transfer_id)->not->toBeNull()
        ->and($in->refresh()->transfer_id)->toBe($out->transfer_id);
});

it('par ambíguo não liga, vira sugestão para cada combinação', function () {
    $out = leg($this->checking, Direction::Out);
    $extraAccount = Account::factory()->create(['user_id' => $this->user->id]);
    $in1 = leg($this->savings, Direction::In);
    $in2 = leg($extraAccount, Direction::In);

    $result = $this->detect->handle([$out->id, $in1->id, $in2->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 2]);

    expect($out->refresh()->transfer_id)->toBeNull();

    $suggestions = TransferSuggestion::query()->where('status', TransferSuggestionStatus::Pending)->get();
    expect($suggestions)->toHaveCount(2)
        ->and($suggestions->pluck('out_transaction_id')->unique()->all())->toBe([$out->id]);
});

it('reexecutar com os mesmos ids não duplica sugestão nem religa o par já ligado', function () {
    $out = leg($this->checking, Direction::Out);
    $in = leg($this->savings, Direction::In);

    $this->detect->handle([$out->id, $in->id]);
    $firstTransferId = $out->refresh()->transfer_id;

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0])
        ->and($out->refresh()->transfer_id)->toBe($firstTransferId);

    $extraAccount = Account::factory()->create(['user_id' => $this->user->id]);
    $otherOut = leg($this->checking, Direction::Out, ['amount' => 30000]);
    $in1 = leg($this->savings, Direction::In, ['amount' => 30000]);
    $in2 = leg($extraAccount, Direction::In, ['amount' => 30000]);

    $this->detect->handle([$otherOut->id, $in1->id, $in2->id]);
    $resultAgain = $this->detect->handle([$otherOut->id, $in1->id, $in2->id]);

    expect($resultAgain)->toBe(['linked' => 0, 'suggested' => 0])
        ->and(TransferSuggestion::count())->toBe(2);
});

it('par descartado não volta a ligar nem a sugerir', function () {
    $out = leg($this->checking, Direction::Out);
    $in = leg($this->savings, Direction::In);

    TransferSuggestion::factory()->create([
        'user_id' => $this->user->id,
        'out_transaction_id' => $out->id,
        'in_transaction_id' => $in->id,
        'status' => TransferSuggestionStatus::Dismissed,
        'score' => 0,
    ]);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0])
        ->and($out->refresh()->transfer_id)->toBeNull()
        ->and(TransferSuggestion::query()->where('status', TransferSuggestionStatus::Pending)->count())->toBe(0);
});

it('transação ignorada não entra como candidata', function () {
    $out = leg($this->checking, Direction::Out, ['is_ignored' => true]);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0]);
});

it('transação projetada não entra como candidata', function () {
    $out = leg($this->checking, Direction::Out, ['status' => 'projected']);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0]);
});

it('parcela não entra como candidata', function () {
    $plan = InstallmentPlan::factory()->create(['account_id' => $this->checking->id]);
    $out = leg($this->checking, Direction::Out, ['installment_plan_id' => $plan->id, 'installment_number' => 1]);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0]);
});

it('isola candidatas por usuário: mesmo valor e data de outro usuário não forma par', function () {
    $out = leg($this->checking, Direction::Out);
    actingAsUser();
    $otherAccount = Account::factory()->create(['user_id' => auth()->id()]);
    $foreignIn = leg($otherAccount, Direction::In);

    $result = $this->detect->handle([$out->id, $foreignIn->id]);

    // O global scope de BelongsToUser já torna a transação do outro usuário
    // inexistente para quem chama detect agora (o usuário autenticado é o
    // segundo, não o dono de $out) — nenhum dos dois é candidata válida
    // junto do outro.
    expect($result)->toBe(['linked' => 0, 'suggested' => 0]);
});

it('sem ids, considera só candidatas dentro da janela de dias pedida', function () {
    $out = leg($this->checking, Direction::Out, ['date' => '2026-01-01']);
    $in = leg($this->savings, Direction::In, ['date' => '2026-01-01']);

    $result = $this->detect->handle(null, 30);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0]);

    $resultWide = $this->detect->handle(null, 365);

    expect($resultWide)->toBe(['linked' => 1, 'suggested' => 0]);
    expect($out->refresh()->transfer_id)->not->toBeNull();
    expect($in->refresh()->transfer_id)->toBe($out->transfer_id);
});
