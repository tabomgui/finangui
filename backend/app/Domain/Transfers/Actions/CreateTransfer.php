<?php

namespace App\Domain\Transfers\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Actions\AssignStatement;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Data\TransferData;
use App\Domain\Transfers\Errors\TransferCurrencyMismatch;
use App\Domain\Transfers\Errors\TransferSameAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateTransfer
{
    public function __construct(private readonly AssignStatement $assignStatement) {}

    /**
     * @return array{out: Transaction, in: Transaction}
     *
     * @throws TransferSameAccount
     * @throws TransferCurrencyMismatch
     */
    public function handle(TransferData $data): array
    {
        if ($data->fromAccountId === $data->toAccountId) {
            throw new TransferSameAccount;
        }

        $from = Account::query()->findOrFail($data->fromAccountId);
        $to = Account::query()->findOrFail($data->toAccountId);

        if ($from->currency !== $to->currency) {
            throw new TransferCurrencyMismatch;
        }

        $transferId = (string) Str::uuid();

        return DB::transaction(fn () => [
            'out' => $this->leg($from, Direction::Out, $data, $transferId),
            'in' => $this->leg($to, Direction::In, $data, $transferId),
        ]);
    }

    private function leg(Account $account, Direction $direction, TransferData $data, string $transferId): Transaction
    {
        $leg = new Transaction([
            'account_id' => $account->id,
            'date' => $data->date,
            'amount' => $data->amount,
            'direction' => $direction,
            'currency' => $account->currency,
            'description' => $data->description,
            'original_description' => $data->description,
            'notes' => $data->notes,
            'status' => TransactionStatus::Posted,
            'source' => TransactionSource::Manual,
            'transfer_id' => $transferId,
        ]);

        $this->assignStatement->handle($leg, $direction === Direction::In ? $data->toStatementId : null);
        $leg->save();

        return $leg->load('account');
    }
}
