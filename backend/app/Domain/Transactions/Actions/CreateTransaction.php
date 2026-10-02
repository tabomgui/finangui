<?php

namespace App\Domain\Transactions\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Data\TransactionData;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;

final class CreateTransaction
{
    public function handle(TransactionData $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $account = Account::query()->findOrFail($data->accountId);

            $transaction = Transaction::create([
                'account_id' => $account->id,
                'date' => $data->date,
                'amount' => $data->amount,
                'direction' => $data->direction,
                'currency' => $account->currency,
                'description' => $data->description,
                'original_description' => $data->description,
                'notes' => $data->notes,
                'payee' => $data->payee,
                'category_id' => $data->categoryId,
                'categorized_by' => $data->categoryId !== null ? 'manual' : null,
                'status' => TransactionStatus::Posted,
                'source' => TransactionSource::Manual,
                'is_ignored' => $data->isIgnored,
            ]);

            $transaction->tags()->sync($data->tagIds);

            return $transaction->load(['account', 'category', 'tags']);
        });
    }
}
