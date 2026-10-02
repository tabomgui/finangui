<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Support\TransferLegs;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class DeleteTransfer
{
    /**
     * @throws ModelNotFoundException<Transaction>
     */
    public function handle(string $transferId): void
    {
        DB::transaction(function () use ($transferId) {
            TransferLegs::load($transferId, lock: true);
            Transaction::query()->where('transfer_id', $transferId)->delete();
        });
    }
}
