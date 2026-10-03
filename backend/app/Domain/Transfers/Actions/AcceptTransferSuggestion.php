<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Errors\TransferLinkInvalid;
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
     * @throws TransferLinkInvalid
     * @throws ModelNotFoundException<Transaction>
     */
    public function handle(TransferSuggestion $suggestion): array
    {
        if ($suggestion->status !== TransferSuggestionStatus::Pending) {
            throw new TransferSuggestionNotPending;
        }

        $out = Transaction::query()->findOrFail($suggestion->out_transaction_id);
        $in = Transaction::query()->findOrFail($suggestion->in_transaction_id);

        try {
            // A mesma janela da detecção automática: a sugestão só existe
            // para pares dentro de ±2 dias (ver TransferMatcher::isPossiblePair()).
            $this->linkTransfer->handle($out, $in);
        } catch (TransferLinkInvalid $e) {
            // Sugestão desatualizada (ex.: uma das pernas já ligou com
            // outra, por uma corrida entre duas detecções): não é mais um
            // par válido, então não faz sentido continuar pendente —
            // exclui antes de relançar, pra não ficar presa pra sempre
            // (LinkTransfer só exclui sugestões quando liga de verdade).
            $suggestion->delete();

            throw $e;
        }

        return ['out' => $out->refresh()->load('account'), 'in' => $in->refresh()->load('account')];
    }
}
