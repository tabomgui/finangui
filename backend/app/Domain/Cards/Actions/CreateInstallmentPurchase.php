<?php

namespace App\Domain\Cards\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Errors\InstallmentAmountTooSmall;
use App\Domain\Cards\Errors\InstallmentsRequireCard;
use App\Domain\Cards\Errors\StatementAccountMismatch;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Cards\Support\InstallmentSplit;
use App\Domain\Cards\Support\StatementResolver;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Transactions\Data\TransactionData;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionSource;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Compra em N vezes: um plano e N transações, uma por fatura consecutiva.
 * Parcela k tem a data da compra + (k−1) meses; nasce lançada se a data já
 * chegou, projetada se não (PostDueInstallments lança quando chegar).
 */
final class CreateInstallmentPurchase
{
    public function __construct(
        private readonly StatementResolver $resolver,
        private readonly AssignStatement $assignStatement,
        private readonly CategorizeTransaction $categorize,
    ) {}

    /**
     * @throws InstallmentsRequireCard
     * @throws InstallmentAmountTooSmall
     * @throws StatementAccountMismatch
     */
    public function handle(TransactionData $data): Transaction
    {
        return DB::transaction(function () use ($data) {
            $card = Account::query()->findOrFail($data->accountId);

            if (! $card->isCreditCard() || $data->direction !== Direction::Out) {
                throw new InstallmentsRequireCard;
            }

            $amounts = InstallmentSplit::split($data->amount, $data->installments);

            $categoryId = $data->categoryId;
            $categorizedBy = $data->categoryId !== null ? 'manual' : null;

            if ($categoryId === null) {
                $preview = new Transaction([
                    'account_id' => $card->id,
                    'date' => $data->date,
                    'amount' => $amounts[0],
                    'direction' => Direction::Out,
                    'currency' => $card->currency,
                    'description' => $data->description,
                    'original_description' => $data->description,
                    'notes' => $data->notes,
                    'payee' => $data->payee,
                ]);

                $suggestion = $this->categorize->suggest($preview);

                if ($suggestion !== null) {
                    $categoryId = $suggestion['category_id'];
                    $categorizedBy = $suggestion['categorized_by'];
                }
            }

            $plan = InstallmentPlan::create([
                'account_id' => $card->id,
                'description' => $data->description,
                'total_amount' => $data->amount,
                'installments' => $data->installments,
                'purchase_date' => $data->date,
            ]);

            $today = CarbonImmutable::today();
            $statement = $data->statementId !== null
                ? $this->assignStatement->chosen($card, $data->statementId)
                : $this->resolver->forDate($card, $data->date);
            $first = null;

            foreach ($amounts as $index => $amount) {
                if ($index > 0) {
                    $statement = $this->resolver->next($card, $statement);
                }
                $date = $data->date->addMonthsNoOverflow($index);

                $parcel = Transaction::create([
                    'account_id' => $card->id,
                    'date' => $date,
                    'amount' => $amount,
                    'direction' => Direction::Out,
                    'currency' => $card->currency,
                    'description' => $data->description,
                    'original_description' => $data->description,
                    'notes' => $data->notes,
                    'payee' => $data->payee,
                    'category_id' => $categoryId,
                    'categorized_by' => $categorizedBy,
                    'status' => $date->lessThanOrEqualTo($today) ? TransactionStatus::Posted : TransactionStatus::Projected,
                    'source' => $index === 0 ? TransactionSource::Manual : TransactionSource::Installment,
                    'is_ignored' => $data->isIgnored,
                    'statement_id' => $statement->id,
                    'installment_plan_id' => $plan->id,
                    'installment_number' => $index + 1,
                ]);
                $parcel->tags()->sync($data->tagIds);
                $first ??= $parcel;
            }

            return $first->load(['account', 'category.parent', 'tags', 'installmentPlan']);
        });
    }
}
