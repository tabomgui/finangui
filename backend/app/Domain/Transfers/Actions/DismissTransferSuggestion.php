<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Errors\TransferSuggestionNotPending;
use App\Domain\Transfers\Models\TransferSuggestion;

/**
 * Descarta uma sugestão pendente: fica `dismissed` (não exclui a linha),
 * para a detecção não voltar a sugerir o mesmo par.
 */
final class DismissTransferSuggestion
{
    /**
     * @throws TransferSuggestionNotPending
     */
    public function handle(TransferSuggestion $suggestion): void
    {
        if ($suggestion->status !== TransferSuggestionStatus::Pending) {
            throw new TransferSuggestionNotPending;
        }

        $suggestion->status = TransferSuggestionStatus::Dismissed;
        $suggestion->save();
    }
}
