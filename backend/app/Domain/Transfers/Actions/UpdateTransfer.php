<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Errors\TransferCurrencyMismatch;
use App\Domain\Transfers\Errors\TransferSameAccount;
use App\Domain\Transfers\Support\TransferLegs;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class UpdateTransfer
{
    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     * @return array{out: Transaction, in: Transaction}
     *
     * @throws ModelNotFoundException<Transaction>
     * @throws TransferSameAccount
     * @throws TransferCurrencyMismatch
     */
    public function handle(string $transferId, array $input): array
    {
        return DB::transaction(function () use ($transferId, $input) {
            ['out' => $out, 'in' => $in] = TransferLegs::load($transferId, lock: true);

            if (array_key_exists('from_account_id', $input)) {
                $out->account_id = (int) $input['from_account_id'];
            }
            if (array_key_exists('to_account_id', $input)) {
                $in->account_id = (int) $input['to_account_id'];
            }
            if ($out->account_id === $in->account_id) {
                throw new TransferSameAccount;
            }

            $from = Account::query()->findOrFail($out->account_id);
            $to = Account::query()->findOrFail($in->account_id);
            if ($from->currency !== $to->currency) {
                throw new TransferCurrencyMismatch;
            }

            foreach ([$out, $in] as $leg) {
                $leg->currency = $from->currency;
                if (array_key_exists('date', $input)) {
                    $leg->date = $input['date'];
                }
                if (array_key_exists('amount', $input)) {
                    $leg->amount = Money::cents((int) $input['amount']);
                }
                if (array_key_exists('description', $input)) {
                    $leg->description = $input['description'];
                    $leg->description_locked = true;
                }
                if (array_key_exists('notes', $input)) {
                    $leg->notes = $input['notes'];
                }
                $leg->save();
            }

            return ['out' => $out->load('account'), 'in' => $in->load('account')];
        });
    }
}
