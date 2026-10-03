<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\LinkTransfer;
use App\Domain\Transfers\Actions\UnlinkTransfer;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Models\TransferSuggestion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 15)->startOfDay());
    $this->user = actingAsUser();
    $this->checking = Account::factory()->create(['user_id' => $this->user->id]);
    $this->savings = Account::factory()->create(['user_id' => $this->user->id]);
});

it('desfaz uma transferência: duas transações comuns e uma sugestão dismissed para o par', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 5000, 'date' => '2026-03-15']);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 5000, 'date' => '2026-03-15']);
    $transferId = app(LinkTransfer::class)->handle($out, $in);

    app(UnlinkTransfer::class)->handle($transferId);

    $out->refresh();
    $in->refresh();

    expect($out->transfer_id)->toBeNull()
        ->and($in->transfer_id)->toBeNull();

    $suggestion = TransferSuggestion::query()
        ->where('out_transaction_id', $out->id)
        ->where('in_transaction_id', $in->id)
        ->first();

    expect($suggestion)->not->toBeNull()
        ->and($suggestion->status)->toBe(TransferSuggestionStatus::Dismissed);
});

it('desfazer de novo lança 404: as pernas não compartilham mais o transfer_id', function () {
    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 5000, 'date' => '2026-03-15']);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 5000, 'date' => '2026-03-15']);
    $transferId = app(LinkTransfer::class)->handle($out, $in);

    app(UnlinkTransfer::class)->handle($transferId);

    expect(fn () => app(UnlinkTransfer::class)->handle($transferId))->toThrow(ModelNotFoundException::class);
});

it('entrada no cartão recalcula a fatura pela data normal (AssignStatement comum)', function () {
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $closed = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);
    $open = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-04-10', 'due_date' => '2026-04-20']);

    $out = Transaction::factory()->create(['account_id' => $this->checking->id, 'direction' => Direction::Out, 'amount' => 3000, 'date' => '2026-03-15']);
    $in = Transaction::factory()->create(['account_id' => $card->id, 'direction' => Direction::In, 'amount' => 3000, 'date' => '2026-03-15']);
    $transferId = app(LinkTransfer::class)->handle($out, $in);

    expect($in->refresh()->statement_id)->toBe($closed->id);

    app(UnlinkTransfer::class)->handle($transferId);

    // Fora de transferência, a data 2026-03-15 cai na fatura seguinte
    // (que fecha em 2026-04-10), não na última fechada.
    expect($in->refresh()->statement_id)->toBe($open->id);
});

it('saída de cartão mantém o statement_id como estava ao desfazer', function () {
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-03-10', 'due_date' => '2026-03-20']);

    $out = Transaction::factory()->create(['account_id' => $card->id, 'direction' => Direction::Out, 'amount' => 3000, 'date' => '2026-03-05', 'statement_id' => $statement->id]);
    $in = Transaction::factory()->create(['account_id' => $this->savings->id, 'direction' => Direction::In, 'amount' => 3000, 'date' => '2026-03-05']);
    $transferId = app(LinkTransfer::class)->handle($out, $in);

    app(UnlinkTransfer::class)->handle($transferId);

    expect($out->refresh()->statement_id)->toBe($statement->id);
});
