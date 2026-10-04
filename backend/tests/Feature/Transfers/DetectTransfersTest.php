<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
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
        // "Transferência" dá a mesma pista (TRANSF) a todas as pernas por
        // padrão — a ligação automática agora exige evidência; os testes
        // que querem testar ambiguidade continuam válidos porque a pista
        // soma igualmente aos dois lados de um empate, sem desfazê-lo.
        'description' => 'Transferência comum',
    ], $overrides));
}

it('liga automaticamente um par inequívoco entre contas diferentes', function () {
    $out = leg($this->checking, Direction::Out);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 1, 'suggested' => 0, 'undo' => []]);

    expect($out->refresh()->transfer_id)->not->toBeNull()
        ->and($in->refresh()->transfer_id)->toBe($out->transfer_id);
});

it('par ambíguo não liga, vira sugestão para cada combinação', function () {
    $out = leg($this->checking, Direction::Out);
    $extraAccount = Account::factory()->create(['user_id' => $this->user->id]);
    $in1 = leg($this->savings, Direction::In);
    $in2 = leg($extraAccount, Direction::In);

    $result = $this->detect->handle([$out->id, $in1->id, $in2->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 2, 'undo' => []]);

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

    expect($result)->toBe(['linked' => 0, 'suggested' => 0, 'undo' => []])
        ->and($out->refresh()->transfer_id)->toBe($firstTransferId);

    $extraAccount = Account::factory()->create(['user_id' => $this->user->id]);
    $otherOut = leg($this->checking, Direction::Out, ['amount' => 30000]);
    $in1 = leg($this->savings, Direction::In, ['amount' => 30000]);
    $in2 = leg($extraAccount, Direction::In, ['amount' => 30000]);

    $this->detect->handle([$otherOut->id, $in1->id, $in2->id]);
    $resultAgain = $this->detect->handle([$otherOut->id, $in1->id, $in2->id]);

    expect($resultAgain)->toBe(['linked' => 0, 'suggested' => 0, 'undo' => []])
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

    expect($result)->toBe(['linked' => 0, 'suggested' => 0, 'undo' => []])
        ->and($out->refresh()->transfer_id)->toBeNull()
        ->and(TransferSuggestion::query()->where('status', TransferSuggestionStatus::Pending)->count())->toBe(0);
});

it('transação ignorada não entra como candidata', function () {
    $out = leg($this->checking, Direction::Out, ['is_ignored' => true]);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0, 'undo' => []]);
});

it('transação projetada não entra como candidata', function () {
    $out = leg($this->checking, Direction::Out, ['status' => 'projected']);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0, 'undo' => []]);
});

it('parcela não entra como candidata', function () {
    $plan = InstallmentPlan::factory()->create(['account_id' => $this->checking->id]);
    $out = leg($this->checking, Direction::Out, ['installment_plan_id' => $plan->id, 'installment_number' => 1]);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0, 'undo' => []]);
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
    expect($result)->toBe(['linked' => 0, 'suggested' => 0, 'undo' => []]);
});

it('sem ids, considera só candidatas dentro da janela de dias pedida', function () {
    $out = leg($this->checking, Direction::Out, ['date' => '2026-01-01']);
    $in = leg($this->savings, Direction::In, ['date' => '2026-01-01']);

    $result = $this->detect->handle(null, 30);

    expect($result)->toBe(['linked' => 0, 'suggested' => 0, 'undo' => []]);

    $resultWide = $this->detect->handle(null, 365);

    expect($resultWide)->toBe(['linked' => 1, 'suggested' => 0, 'undo' => []]);
    expect($out->refresh()->transfer_id)->not->toBeNull();
    expect($in->refresh()->transfer_id)->toBe($out->transfer_id);
});

it('sem evidência na descrição, o par mútuo não liga sozinho — só sugere', function () {
    $out = leg($this->checking, Direction::Out, ['description' => 'Lancamento qualquer']);
    $in = leg($this->savings, Direction::In, ['description' => 'Lancamento qualquer']);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 1, 'undo' => []])
        ->and($out->refresh()->transfer_id)->toBeNull();
});

it('perna pendente nunca liga automaticamente, mesmo com evidência — só sugere', function () {
    $out = leg($this->checking, Direction::Out, ['status' => 'pending']);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result)->toBe(['linked' => 0, 'suggested' => 1, 'undo' => []])
        ->and($out->refresh()->transfer_id)->toBeNull();
});

it('compra no cartão de crédito e um PIX recebido do mesmo valor não ligam sozinhos — só sugerem', function () {
    $card = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);

    $purchase = leg($card, Direction::Out, ['description' => 'Compra loja exemplo']);
    $pix = leg($this->savings, Direction::In, ['description' => 'Pix recebido']);

    $result = $this->detect->handle([$purchase->id, $pix->id]);

    expect($result['linked'])->toBe(0)
        ->and($result['suggested'])->toBe(1)
        ->and($purchase->refresh()->transfer_id)->toBeNull();
});

it('estorno manualmente categorizado e assinatura manualmente categorizada não ligam sozinhos, mesmo com pista de pagamento', function () {
    $categoriaCompras = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Compras', 'is_transfer' => false]);
    $categoriaAssinaturas = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Assinaturas', 'is_transfer' => false]);

    $estorno = leg($this->checking, Direction::In, [
        'description' => 'Estorno de pagamento', 'category_id' => $categoriaCompras->id, 'categorized_by' => 'manual',
    ]);
    $assinatura = leg($this->savings, Direction::Out, [
        'description' => 'Pagamento Spotify', 'category_id' => $categoriaAssinaturas->id, 'categorized_by' => 'manual',
    ]);

    $result = $this->detect->handle([$estorno->id, $assinatura->id]);

    expect($result['linked'])->toBe(0)
        ->and($result['suggested'])->toBe(1)
        ->and($estorno->refresh()->transfer_id)->toBeNull()
        ->and($estorno->category_id)->toBe($categoriaCompras->id);
});

it('categoria manual marcada como transferência não impede a ligação automática', function () {
    $categoriaTransferencia = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transferências', 'is_transfer' => true]);

    $out = leg($this->checking, Direction::Out, ['category_id' => $categoriaTransferencia->id, 'categorized_by' => 'manual']);
    $in = leg($this->savings, Direction::In);

    $result = $this->detect->handle([$out->id, $in->id]);

    expect($result['linked'])->toBe(1)
        ->and($out->refresh()->transfer_id)->not->toBeNull();
});

it('grava em undo a categoria/fatura de antes de ligar, para a perna de fora do lote', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $existing = leg($this->savings, Direction::In, ['category_id' => $category->id, 'categorized_by' => 'history']);
    $inserted = leg($this->checking, Direction::Out);

    $result = $this->detect->handle([$inserted->id]);

    expect($result['linked'])->toBe(1)
        ->and($result['undo'])->toBe([
            ['transaction_id' => $existing->id, 'attributes' => [
                'category_id' => $category->id, 'categorized_by' => 'history', 'statement_id' => null,
            ]],
        ]);

    expect($existing->refresh()->category_id)->toBeNull();
});

it('ao ligar de verdade a melhor opção, a segunda melhor opção da mesma entrada deixa de ser sugerida', function () {
    // in prefere out1 (mesma data: 0,8) sobre out2 (1 dia: 0,65) — só
    // out1-in é mútuo e liga; out2 nunca seria ligado por si só, e some da
    // lista de sugestões porque in realmente ligou com out1.
    $in = leg($this->savings, Direction::In);
    $out1 = leg($this->checking, Direction::Out);
    $extraAccount = Account::factory()->create(['user_id' => $this->user->id]);
    $out2 = leg($extraAccount, Direction::Out, ['date' => '2026-10-01']);

    $result = $this->detect->handle([$out1->id, $in->id, $out2->id]);

    expect($result['linked'])->toBe(1)
        ->and($result['suggested'])->toBe(0)
        ->and($out1->refresh()->transfer_id)->not->toBeNull()
        ->and($in->refresh()->transfer_id)->toBe($out1->transfer_id)
        ->and($out2->refresh()->transfer_id)->toBeNull();

    expect(TransferSuggestion::query()->where('status', TransferSuggestionStatus::Pending)->count())->toBe(0);
});
