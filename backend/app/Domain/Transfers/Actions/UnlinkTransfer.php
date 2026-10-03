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
     * @throws ModelNotFoundException<Transaction>
     */
    public function handle(string $transferId): void
    {
        DB::transaction(function () use ($transferId) {
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

            TransferSuggestion::query()->updateOrCreate(
                ['out_transaction_id' => $out->id, 'in_transaction_id' => $in->id],
                ['score' => 0, 'status' => TransferSuggestionStatus::Dismissed],
            );
        });
    }
}
