<?php

namespace App\Domain\Transactions\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Errors\TransferLegLocked;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdateTransaction
{
    /** Campos que, numa perna de transferência, só mudam pelo endpoint de transferência. */
    private const TRANSFER_LOCKED = ['account_id', 'date', 'amount', 'direction'];

    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     */
    public function handle(Transaction $transaction, array $input): Transaction
    {
        if ($transaction->isTransferLeg() && array_intersect(array_keys($input), self::TRANSFER_LOCKED) !== []) {
            throw new TransferLegLocked;
        }

        return DB::transaction(function () use ($transaction, $input) {
            $tagIds = Arr::pull($input, 'tag_ids');

            $transaction->fill($input);

            if ($transaction->isDirty('description')) {
                $transaction->description_locked = true;
            }
            if ($transaction->isDirty('category_id')) {
                $transaction->categorized_by = $transaction->category_id !== null ? 'manual' : null;
            }
            if ($transaction->isDirty('account_id')) {
                $transaction->currency = Account::query()->findOrFail($transaction->account_id)->currency;
            }

            $transaction->save();

            if ($tagIds !== null) {
                $transaction->tags()->sync($tagIds);
            }

            return $transaction->load(['account', 'category', 'tags']);
        });
    }
}
