<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Errors\StatementAlreadyPaid;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\CreateTransfer;
use App\Domain\Transfers\Data\TransferData;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pagar fatura é uma transferência de outra conta para o cartão, com a perna
 * de entrada ligada à fatura paga. Nunca uma despesa.
 */
final class PayStatement
{
    public function __construct(private readonly CreateTransfer $createTransfer) {}

    /**
     * @return array{out: Transaction, in: Transaction}
     *
     * @throws StatementAlreadyPaid
     */
    public function handle(CardStatement $statement, int $fromAccountId, Money $amount, CarbonImmutable $date, ?string $description = null): array
    {
        return DB::transaction(function () use ($statement, $fromAccountId, $amount, $date, $description) {
            // Dois pagamentos simultâneos da mesma fatura não passam juntos pela checagem.
            Account::query()->whereKey($statement->account_id)->lockForUpdate()->first();
            $loaded = CardStatement::query()->withTotals()->findOrFail($statement->id);

            if ($loaded->remaining()->cents <= 0) {
                throw new StatementAlreadyPaid;
            }

            return $this->createTransfer->handle(new TransferData(
                fromAccountId: $fromAccountId,
                toAccountId: $statement->account_id,
                date: $date,
                amount: $amount,
                description: $description ?? 'Pagamento de fatura',
                toStatementId: $statement->id,
            ));
        });
    }
}
