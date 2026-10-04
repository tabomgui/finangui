<?php

namespace App\Http\Requests\Recurrences;

use App\Domain\Categories\Enums\CategoryKind;
use App\Domain\Categories\Models\Category;
use App\Domain\Recurrences\Enums\Frequency;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\Direction;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Atualização parcial (PATCH). direction não entra nas regras: é imutável
 * depois de criada (fora do escopo das regras de edição do plano).
 */
final class UpdateRecurrenceRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['sometimes', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->userId())],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->userId())],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'amount' => ['sometimes', 'integer', 'min:1', 'max:1000000000000000'],
            'frequency' => ['sometimes', Rule::enum(Frequency::class)],
            'interval' => ['sometimes', 'integer', 'min:1', 'max:12'],
            'day_of_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
            'starts_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'match_pattern' => ['sometimes', 'nullable', 'string', 'max:80'],
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
        $frequency = $this->input('frequency', $this->recurrence()->frequency->value);

        if ($frequency !== Frequency::Monthly->value && $this->filled('day_of_month')) {
            $validator->errors()->add('day_of_month', 'day_of_month só vale para frequência mensal.');
        }
    }

    private function validateEndsOn(Validator $validator): void
    {
        $recurrence = $this->recurrence();
        $endsOn = $this->has('ends_on') ? $this->input('ends_on') : $recurrence->ends_on?->toDateString();

        if ($endsOn === null) {
            return;
        }

        $startsOn = $this->input('starts_on', $recurrence->starts_on->toDateString());

        if ($endsOn < $startsOn) {
            $validator->errors()->add('ends_on', 'O fim precisa ser igual ou depois do início.');
        }
    }

    private function validateCategoryDirection(Validator $validator): void
    {
        $recurrence = $this->recurrence();
        $categoryId = $this->has('category_id') ? $this->input('category_id') : $recurrence->category_id;

        if ($categoryId === null) {
            return;
        }

        $category = Category::query()->find($categoryId);

        if ($category === null) {
            return;
        }

        $expectedKind = $recurrence->direction === Direction::Out ? CategoryKind::Expense : CategoryKind::Income;

        if ($category->kind !== $expectedKind) {
            $validator->errors()->add('category_id', 'A categoria não é compatível com o tipo do lançamento.');
        }
    }

    private function recurrence(): Recurrence
    {
        $recurrence = $this->route('recurrence');
        assert($recurrence instanceof Recurrence);

        return $recurrence;
    }
}
