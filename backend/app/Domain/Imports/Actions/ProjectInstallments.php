<?php

namespace App\Domain\Imports\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Cards\Support\StatementResolver;
use App\Domain\Imports\Data\ParsedRow;
use App\Domain\Imports\Models\ImportBatch;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * A parte de IngestTransactions que cria o plano de uma parcela nova e
 * projeta as parcelas seguintes — extraído só para IngestTransactions não
 * crescer: nenhuma trava/decisão própria, sempre chamado de dentro da
 * transação de banco de IngestTransactions::handle().
 */
final class ProjectInstallments
{
    public function __construct(
        private readonly StatementResolver $statementResolver,
    ) {}

    /**
     * purchase_date estimada: a data da linha menos (N−1) meses, sem
     * overflow (ex.: parcela 2/10 lançada em 31/03 "nasceu" em 28 ou 29/02).
     */
    public function createPlan(ImportBatch $batch, Account $account, ParsedRow $row): InstallmentPlan
    {
        /** @var array{number: int, total: int} $installment */
        $installment = $row->installment;

        return InstallmentPlan::create([
            'account_id' => $account->id,
            'description' => $row->description,
            'installments' => $installment['total'],
            'purchase_date' => CarbonImmutable::parse($row->date)->subMonthsNoOverflow($installment['number'] - 1),
            'total_amount' => $row->amount * $installment['total'],
            'import_batch_id' => $batch->id,
        ]);
    }

    /**
     * Projeta as parcelas N+1..M (a linha importada já gravou a parcela N):
     * mesmo valor da linha, uma fatura seguinte por parcela
     * (StatementResolver::next), lançada se a data já chegou, e herdando da
     * parcela N tudo que a categorização/regras podem ter mudado nela
     * (descrição, favorecido, ignorada, categoria/categorized_by e tags) —
     * original_description continua sendo a da própria linha de cada
     * parcela, não a da parcela N.
     */
    public function projectRemaining(
        ImportBatch $batch,
        Account $account,
        InstallmentPlan $plan,
        ParsedRow $row,
        Transaction $parcel,
    ): void {
        /** @var array{number: int, total: int} $installment */
        $installment = $row->installment;
        $today = CarbonImmutable::today();
        $rowDate = CarbonImmutable::parse($row->date);
        $statement = CardStatement::query()->findOrFail($parcel->statement_id);
        $tagIds = $parcel->tags->pluck('id')->all();

        for ($number = $installment['number'] + 1; $number <= $installment['total']; $number++) {
            $statement = $this->statementResolver->next($account, $statement);
            $date = $rowDate->addMonthsNoOverflow($number - $installment['number']);

            $projected = Transaction::create([
                'account_id' => $account->id,
                'date' => $date,
                'amount' => $row->amount,
                'direction' => $row->direction,
                'currency' => $account->currency,
                'description' => $parcel->description,
                'original_description' => $row->description,
                'payee' => $parcel->payee,
                'is_ignored' => $parcel->is_ignored,
                'status' => $date->lessThanOrEqualTo($today) ? TransactionStatus::Posted : TransactionStatus::Projected,
                'source' => TransactionSource::Installment,
                'statement_id' => $statement->id,
                'installment_plan_id' => $plan->id,
                'installment_number' => $number,
                'import_batch_id' => $batch->id,
                'category_id' => $parcel->category_id,
                'categorized_by' => $parcel->categorized_by,
            ]);

            if ($tagIds !== []) {
                $projected->tags()->sync($tagIds);
            }
        }
    }
}
