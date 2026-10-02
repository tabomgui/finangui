<?php

namespace App\Domain\Transfers\Support;

use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class TransferLegs
{
    /**
     * @return array{out: Transaction, in: Transaction}
     *
     * @throws ModelNotFoundException<Transaction>
     */
    public static function load(string $transferId, bool $lock = false): array
    {
        $query = Transaction::query()->where('transfer_id', $transferId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $legs = $query->get();

        $out = $legs->first(fn (Transaction $t) => $t->direction === Direction::Out);
        $in = $legs->first(fn (Transaction $t) => $t->direction === Direction::In);

        if ($legs->count() !== 2 || $out === null || $in === null) {
            throw (new ModelNotFoundException)->setModel(Transaction::class, [$transferId]);
        }

        return ['out' => $out, 'in' => $in];
    }
}
