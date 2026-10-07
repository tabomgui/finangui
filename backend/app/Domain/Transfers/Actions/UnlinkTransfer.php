<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Enums\TransferSuggestionStatus;
use App\Domain\Transfers\Models\TransferSuggestion;
use App\Domain\Transfers\Support\TransferLegs;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Desfaz uma transferência: as duas pernas voltam a ser lançamentos comuns.
 * Com $remember (desfazer pedido pelo usuário), o par ganha uma sugestão
 * "dismissed", para a detecção não religar as duas sozinha. Sem $remember
 * (desfazer automático: RevertImportBatch, limpeza de pendentes), nenhuma
 * das duas é "decisão do usuário" sobre o par — em vez da sugestão
 * dismissed, cada perna que ficou sem categoria tenta se recategorizar
 * (regras → histórico); quem chama com uma categoria anterior salva (ver
 * App\Domain\Transfers\Actions\DetectTransfers, que grava isso no undo do
 * lote antes de ligar) sobrescreve esse resultado depois, com a categoria
 * de verdade.
 */
final class UnlinkTransfer
{
    public function __construct(
        private readonly AssignStatement $assignStatement,
        private readonly CategorizeTransaction $categorize,
    ) {}

    /**
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
            // agora pela data normal. card_payment_statement_id precisa ser
            // zerado antes de chamar AssignStatement: ele também trata uma
            // entrada já marcada como pagamento (isCardPayment()) como
            // pagamento, justamente para sobreviver a uma reatribuição comum
            // — aqui é o caso contrário, o desfazer em si decide que esta
            // entrada deixou de ser pagamento.
            $in->transfer_id = null;
            $in->card_payment_statement_id = null;
            $this->assignStatement->handle($in);
            $in->save();

            if ($remember) {
                TransferSuggestion::query()->updateOrCreate(
                    ['out_transaction_id' => $out->id, 'in_transaction_id' => $in->id],
                    ['score' => 0, 'status' => TransferSuggestionStatus::Dismissed],
                );
            } else {
                $this->recategorize($out);
                $this->recategorize($in);
            }
        });
    }

    private function recategorize(Transaction $transaction): void
    {
        if ($transaction->category_id !== null) {
            return;
        }

        $this->categorize->handle($transaction);
        $transaction->save();
    }
}
