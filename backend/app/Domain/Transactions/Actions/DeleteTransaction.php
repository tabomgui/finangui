<?php

namespace App\Domain\Transactions\Actions;

use App\Domain\Transactions\Models\Transaction;

final class DeleteTransaction
{
    public function handle(Transaction $transaction): void
    {
        $transaction->delete();
    }
}
