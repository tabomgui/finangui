<?php

namespace App\Domain\Transactions\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Errors\TransactionCurrencyMismatch;
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
        $input = $this->normalizeTypes($input);

        if ($transaction->isTransferLeg() && $this->lockedFieldsChanged($transaction, $input)) {
            throw new TransferLegLocked;
        }

        return DB::transaction(function () use ($transaction, $input) {
            $tagIds = Arr::pull($input, 'tag_ids');

            if (array_key_exists('account_id', $input) && $input['account_id'] !== $transaction->account_id) {
                $destination = Account::query()->findOrFail($input['account_id']);

                if ($destination->currency !== $transaction->currency) {
                    throw new TransactionCurrencyMismatch;
                }
            }

            $transaction->fill($input);

            if ($transaction->isDirty('description')) {
                $transaction->description_locked = true;
            }
            if ($transaction->isDirty('category_id')) {
                $transaction->categorized_by = $transaction->category_id !== null ? 'manual' : null;
            }

            $transaction->save();

            if ($tagIds !== null) {
                $transaction->tags()->sync($tagIds);
            }

            return $transaction->load(['account', 'category', 'tags']);
        });
    }

    /**
     * Garante inteiros reais: "4590" e 4590.0 passam a regra de validação `integer`,
     * mas o MoneyCast só aceita Money ou int de verdade.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalizeTypes(array $input): array
    {
        if (array_key_exists('amount', $input)) {
            $input['amount'] = (int) $input['amount'];
        }
        if (array_key_exists('account_id', $input)) {
            $input['account_id'] = (int) $input['account_id'];
        }
        if (array_key_exists('category_id', $input) && $input['category_id'] !== null) {
            $input['category_id'] = (int) $input['category_id'];
        }

        return $input;
    }

    /**
     * O frontend pode reenviar o objeto inteiro no PATCH; só trava quando um
     * campo bloqueado realmente muda de valor, não apenas por estar presente.
     *
     * @param  array<string, mixed>  $input
     */
    private function lockedFieldsChanged(Transaction $transaction, array $input): bool
    {
        $current = [
            'account_id' => $transaction->account_id,
            'date' => $transaction->date->toDateString(),
            'amount' => $transaction->amount->cents,
            'direction' => $transaction->direction->value,
        ];

        foreach (self::TRANSFER_LOCKED as $field) {
            if (array_key_exists($field, $input) && $input[$field] !== $current[$field]) {
                return true;
            }
        }

        return false;
    }
}
