<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Models\TransferSuggestion;
use App\Domain\Transfers\Support\TransferLegs;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Desfaz uma transferência: as duas pernas voltam a ser lançamentos comuns
 * (sem categoria) e o par ganha uma sugestão "dismissed", para a detecção não
 * religar as duas sozinha.
 */
final class UnlinkTransfer
{
    public function __construct(private readonly AssignStatement $assignStatement) {}

    /**
     * $remember = false pula a sugestão "dismissed": usado pelas limpezas
     * automáticas (RevertImportBatch, limpeza de pendentes do
     * SyncTransactions) que desligam uma perna só para preservar a outra
     * antes de excluir — ali não houve decisão do usuário sobre o par, e
     * uma futura detecção deve poder religá-lo normalmente.
     *
     * @throws ModelNotFoundException<Transaction>
     */
    public function handle(string $transferId, bool $remember = true): void
    {
        DB::transaction(function () use ($transferId, $remember) {
            ['out' => $out, 'in' => $in] = TransferLegs::load($transferId, lock: true);

            $out->transfer_id = null;
            $out->save();

            // A saída de cartão mantém o statement_id como está; só a entrada
            // (que podia estar marcada como pagamento de fatura) recalcula,
            // agora pela data normal — AssignStatement já decide isso sozinho
            // olhando isTransferLeg(), que aqui já é false.
            $in->transfer_id = null;
            $this->assignStatement->handle($in);
            $in->save();

            if ($remember) {
                TransferSuggestion::query()->updateOrCreate(
                    ['out_transaction_id' => $out->id, 'in_transaction_id' => $in->id],
                    ['score' => 0, 'status' => TransferSuggestionStatus::Dismissed],
                );
            }
        });
    }
}
