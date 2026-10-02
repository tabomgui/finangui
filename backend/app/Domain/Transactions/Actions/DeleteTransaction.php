<?php

namespace App\Domain\Transactions\Actions;

use App\Domain\Cards\Actions\DeleteInstallmentPlan;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\DeleteTransfer;

final class DeleteTransaction
{
    public function __construct(
        private readonly DeleteTransfer $deleteTransfer,
        private readonly DeleteInstallmentPlan $deleteInstallmentPlan,
    ) {}

    /**
     * Excluir uma perna de transferência exclui a transferência inteira:
     * uma perna sozinha deixaria o saldo das contas inconsistente. Excluir
     * uma parcela exclui o parcelamento inteiro: a mesma razão.
     */
    public function handle(Transaction $transaction): void
    {
        if ($transaction->isInstallment()) {
            $this->deleteInstallmentPlan->handle($transaction->installmentPlan);

            return;
        }

        if ($transaction->transfer_id !== null) {
            $this->deleteTransfer->handle($transaction->transfer_id);

            return;
        }

        $transaction->delete();
    }
}
