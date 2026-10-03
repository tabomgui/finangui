<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\LinkTransfer;
use App\Domain\Transfers\Errors\TransferLinkInvalid;
use App\Domain\Transfers\Models\TransferSuggestion;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 15)->startOfDay());
    $this->user = actingAsUser();
    $this->checking = Account::factory()->create(['user_id' => $this->user->id]);
    $this->savings = Account::factory()->create(['user_id' => $this->user->id]);
});

function linkTransfer(Transaction $out, Transaction $in, int $maxDays = 2): string
{
    return app(LinkTransfer::class)->handle($out, $in, $maxDays);
}

it('liga um par válido: mesmo transfer_id, sem categoria, saldos mantidos', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $out = Transaction::factory()->create([
        'account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 50000,
        'date' => '2026-03-14', 'category_id' => $category->id, 'categorized_by' => 'manual',
    ]);
    $in = Transaction::factory()->create([
        'account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 50000,
        'date' => '2026-03-15', 'category_id' => $category->id, 'categorized_by' => 'manual',
    ]);

    $balanceBefore = Account::query()->withBalance()->findOrFail($this->checking->id)->balance_net;

    $transferId = linkTransfer($out, $in);

    expect($transferId)->toBeString();

    $out->refresh();
    $in->refresh();

    expect($out->transfer_id)->toBe($transferId)
        ->and($in->transfer_id)->toBe($transferId)
        ->and($out->category_id)->toBeNull()
        ->and($out->categorized_by)->toBeNull()
        ->and($in->category_id)->toBeNull()
        ->and($in->categorized_by)->toBeNull();

    $balanceAfter = Account::query()->withBalance()->findOrFail($this->checking->id)->balance_net;
    expect($balanceAfter)->toBe($balanceBefore);

    expect(Transaction::query()->reportable()->whereKey([$out->id, $in->id])->count())->toBe(0);
});

it('entrada no cartão ganha a fatura certa (última fechada)', function () {
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);

    $out = Transaction::factory()->create([
        'account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 10000, 'date' => '2026-03-15',
    ]);
    $in = Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => Direction::In, 'amount' => 10000, 'date' => '2026-03-15',
    ]);

    linkTransfer($out, $in);

    expect($in->refresh()->statement_id)->toBe($statement->id);
});

it('recusa ligar uma transação que já é perna de transferência', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000, 'transfer_id' => (string) Str::uuid()]);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 1000]);

    expect(fn () => linkTransfer($out, $in))->toThrow(TransferLinkInvalid::class);
});

it('recusa mesma conta', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000]);
    $in = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::In, 'amount' => 1000]);

    expect(fn () => linkTransfer($out, $in))->toThrow(TransferLinkInvalid::class);
});

it('recusa valores diferentes', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000]);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 1001]);

    expect(fn () => linkTransfer($out, $in))->toThrow(TransferLinkInvalid::class);
});

it('recusa direções iguais', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000]);
    $out2 = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::Out, 'amount' => 1000]);

    expect(fn () => linkTransfer($out, $out2))->toThrow(TransferLinkInvalid::class);
});

it('recusa moeda diferente', function () {
    $usd = Account::factory()->create(['user_id' => $this->user->id, 'currency' => 'USD']);
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000, 'currency' => 'BRL']);
    $in = Transaction::factory()->create(['account_id' => $usd->id, 'direction' => Direction::In, 'amount' => 1000, 'currency' => 'USD']);

    expect(fn () => linkTransfer($out, $in))->toThrow(TransferLinkInvalid::class);
});

it('recusa parcela de plano', function () {
    $plan = InstallmentPlan::factory()->create(['account_id' => $this->checking->id]);
    $out = Transaction::factory()->create([
        'account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000,
        'installment_plan_id' => $plan->id, 'installment_number' => 1,
    ]);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 1000]);

    expect(fn () => linkTransfer($out, $in))->toThrow(TransferLinkInvalid::class);
});

it('recusa transação projetada', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000, 'status' => 'projected']);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 1000]);

    expect(fn () => linkTransfer($out, $in))->toThrow(TransferLinkInvalid::class);
});

it('recusa transação ignorada', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000, 'is_ignored' => true]);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 1000]);

    expect(fn () => linkTransfer($out, $in))->toThrow(TransferLinkInvalid::class);
});

it('handleAnyOrder identifica a direção de verdade, independente da ordem dos parâmetros', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000, 'date' => '2026-03-15']);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 1000, 'date' => '2026-03-15']);

    $transferId = app(LinkTransfer::class)->handleAnyOrder($in, $out);

    expect($out->refresh()->transfer_id)->toBe($transferId)
        ->and($in->refresh()->transfer_id)->toBe($transferId);
});

it('handleAnyOrder recusa duas transações com a mesma direção', function () {
    $out1 = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000]);
    $out2 = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::Out, 'amount' => 1000]);

    expect(fn () => app(LinkTransfer::class)->handleAnyOrder($out1, $out2))->toThrow(TransferLinkInvalid::class);
});

it('recusa fora da janela padrão de dias, mas aceita com janela maior', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000, 'date' => '2026-03-01']);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 1000, 'date' => '2026-03-06']);

    expect(fn () => linkTransfer($out, $in))->toThrow(TransferLinkInvalid::class);

    expect(linkTransfer($out, $in, 7))->toBeString();
});

it('exclui sugestões pendentes que envolvem qualquer uma das duas transações', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 1000, 'date' => '2026-03-15']);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 1000, 'date' => '2026-03-15']);
    $unrelated = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 999]);
    $other = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 999]);

    $involvesOut = TransferSuggestion::factory()->create(['out_transaction_id' => $out->id, 'in_transaction_id' => $other->id, 'user_id' => $this->user->id]);
    $involvesIn = TransferSuggestion::factory()->create(['out_transaction_id' => $unrelated->id, 'in_transaction_id' => $in->id, 'user_id' => $this->user->id]);
    $untouched = TransferSuggestion::factory()->create(['out_transaction_id' => $unrelated->id, 'in_transaction_id' => $other->id, 'user_id' => $this->user->id]);

    linkTransfer($out, $in);

    expect(TransferSuggestion::query()->whereKey($involvesOut->id)->exists())->toBeFalse()
        ->and(TransferSuggestion::query()->whereKey($involvesIn->id)->exists())->toBeFalse()
        ->and(TransferSuggestion::query()->whereKey($untouched->id)->exists())->toBeTrue();
});
