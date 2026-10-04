<?php

namespace App\Domain\Transactions\Actions;

use App\Domain\Cards\Actions\DeleteInstallmentPlan;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\DeleteTransfer;
use App\Domain\Transfers\Actions\UnlinkTransfer;
use App\Domain\Transfers\Support\TransferLegs;
use Illuminate\Support\Facades\DB;

final class DeleteTransaction
{
    public function __construct(
        private readonly DeleteTransfer $deleteTransfer,
        private readonly DeleteInstallmentPlan $deleteInstallmentPlan,
        private readonly UnlinkTransfer $unlinkTransfer,
    ) {}

    /**
     * Excluir uma perna de transferência manual (as duas sem external_id)
     * exclui a transferência inteira: uma perna sozinha deixaria o saldo
     * das contas inconsistente. Mas se a outra perna veio do banco
     * (external_id preenchido, ex.: ligada automaticamente pela detecção)
     * ela não pode ser apagada como dano colateral de uma exclusão que o
     * usuário pediu só para esta: desliga o par (sem lembrar como
     * descartado) e exclui só a perna escolhida — a outra volta a ser um
     * lançamento comum. Excluir uma parcela exclui o parcelamento inteiro:
     * a mesma razão de saldo da transferência manual.
     */
    public function handle(Transaction $transaction): void
    {
        if ($transaction->isInstallment()) {
            $this->deleteInstallmentPlan->handle($transaction->installmentPlan);

            return;
        }

        if ($transaction->transfer_id !== null) {
            $legs = TransferLegs::load($transaction->transfer_id);
            $other = $legs['out']->id === $transaction->id ? $legs['in'] : $legs['out'];

            if ($other->external_id !== null) {
                DB::transaction(function () use ($transaction) {
                    $this->unlinkTransfer->handle($transaction->transfer_id, remember: false);
                    $transaction->refresh()->delete();
                });

                return;
            }

            $this->deleteTransfer->handle($transaction->transfer_id);

            return;
        }

        $transaction->delete();
    }
}
