<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Errors\TransferSuggestionNotPending;
use App\Domain\Transfers\Models\TransferSuggestion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Aceitar = ligar as duas transações da sugestão (LinkTransfer, que já
 * exclui a própria sugestão pendente ao ligar) — não existe status
 * "accepted" guardado.
 */
final class AcceptTransferSuggestion
{
    public function __construct(private readonly LinkTransfer $linkTransfer) {}

    /**
     * @return array{out: Transaction, in: Transaction}
     *
     * @throws TransferSuggestionNotPending
     * @throws ModelNotFoundException<Transaction>
     */
    public function handle(TransferSuggestion $suggestion): array
    {
        if ($suggestion->status !== TransferSuggestionStatus::Pending) {
            throw new TransferSuggestionNotPending;
        }

        $out = Transaction::query()->findOrFail($suggestion->out_transaction_id);
        $in = Transaction::query()->findOrFail($suggestion->in_transaction_id);

        // A mesma janela da detecção automática: a sugestão só existe para
        // pares dentro de ±2 dias (ver TransferMatcher::isPossiblePair()).
        $this->linkTransfer->handle($out, $in);

        return ['out' => $out->refresh()->load('account'), 'in' => $in->refresh()->load('account')];
    }
}
