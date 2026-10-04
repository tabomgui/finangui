<?php

namespace App\Http\Requests\Recurrences;

use App\Domain\Categories\Enums\CategoryKind;
use App\Domain\Categories\Models\Category;
use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Com transaction_id, os campos do modelo (account_id, description, amount,
 * direction, starts_on) ficam opcionais: a action lê os que faltarem da
 * transação. frequency continua sempre obrigatório — não existe como
 * derivar isso de um lançamento único.
 */
final class StoreRecurrenceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'transaction_id' => ['sometimes', 'integer', Rule::exists('transactions', 'id')->where('user_id', $this->userId())],
            'account_id' => ['required_without:transaction_id', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->userId())],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->userId())],
            'description' => ['required_without:transaction_id', 'string', 'max:255'],
            'amount' => ['required_without:transaction_id', 'integer', 'min:1', 'max:1000000000000000'],
            'direction' => ['required_without:transaction_id', Rule::enum(Direction::class)],
            'frequency' => ['required', Rule::enum(Frequency::class)],
            'interval' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'starts_on' => ['required_without:transaction_id', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'match_pattern' => ['nullable', 'string', 'max:80'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validateDayOfMonth($validator);
            $this->validateEndsOn($validator);
            $this->validateCategoryDirection($validator);
        }];
    }

    private function validateDayOfMonth(Validator $validator): void
    {
        if ($this->input('frequency') !== Frequency::Monthly->value && $this->filled('day_of_month')) {
            $validator->errors()->add('day_of_month', 'day_of_month só vale para frequência mensal.');
        }
    }

    private function validateEndsOn(Validator $validator): void
    {
        if (! $this->filled('ends_on')) {
            return;
        }

        $startsOn = $this->effectiveStartsOn();

        if ($startsOn !== null && $this->input('ends_on') < $startsOn) {
            $validator->errors()->add('ends_on', 'O fim precisa ser igual ou depois do início.');
        }
    }

    private function validateCategoryDirection(Validator $validator): void
    {
        $categoryId = $this->input('category_id');

        if ($categoryId === null) {
            return;
        }

        $direction = $this->effectiveDirection();
        $category = Category::query()->find($categoryId);

        if ($direction === null || $category === null) {
            return;
        }

        $expectedKind = $direction === Direction::Out->value ? CategoryKind::Expense : CategoryKind::Income;

        if ($category->kind !== $expectedKind) {
            $validator->errors()->add('category_id', 'A categoria não é compatível com o tipo do lançamento.');
        }
    }

    private function effectiveDirection(): ?string
    {
        if ($this->filled('direction')) {
            return $this->input('direction');
        }

        return $this->referencedTransaction()?->direction->value;
    }

    private function effectiveStartsOn(): ?string
    {
        if ($this->filled('starts_on')) {
            return $this->input('starts_on');
        }

        return $this->referencedTransaction()?->date->toDateString();
    }

    private function referencedTransaction(): ?Transaction
    {
        if (! $this->filled('transaction_id')) {
            return null;
        }

        return Transaction::query()->find($this->integer('transaction_id'));
    }
}
