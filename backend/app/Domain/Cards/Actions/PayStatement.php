<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Enums\StatementStatus;
use App\Domain\Cards\Errors\StatementAlreadyPaid;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Models\Transaction;
use App\Domain\Transfers\Actions\CreateTransfer;
use App\Domain\Transfers\Data\TransferData;
use App\Domain\Transfers\Errors\TransferCurrencyMismatch;
use App\Domain\Transfers\Errors\TransferSameAccount;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pagar fatura é uma transferência de outra conta para o cartão, com a perna
 * de entrada ligada à fatura paga. Nunca uma despesa. Uma fatura aberta pode
 * sempre ser paga (até adiantado, mesmo com total zero); pagar mais do que o
 * restante é permitido (o excedente fica como saldo credor do cartão, visível
 * no total/pago da fatura).
 */
final class PayStatement
{
    public function __construct(private readonly CreateTransfer $createTransfer) {}

    /**
     * @return array{out: Transaction, in: Transaction}
     *
     * @throws StatementAlreadyPaid
     * @throws TransferSameAccount
     * @throws TransferCurrencyMismatch
     */
    public function handle(CardStatement $statement, int $fromAccountId, Money $amount, CarbonImmutable $date, ?string $description = null): array
    {
        return DB::transaction(function () use ($statement, $fromAccountId, $amount, $date, $description) {
            // Dois pagamentos simultâneos da mesma fatura não passam juntos pela checagem.
            Account::query()->whereKey($statement->account_id)->lockForUpdate()->first();
            $loaded = CardStatement::query()->withTotals()->findOrFail($statement->id);

            if ($loaded->status(CarbonImmutable::today()) !== StatementStatus::Open && $loaded->remaining()->cents <= 0) {
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
