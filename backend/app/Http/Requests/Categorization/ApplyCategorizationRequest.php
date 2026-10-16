<?php

namespace App\Http\Requests\Categorization;

use App\Domain\Categories\Enums\CategoryKind;
use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Support\RuleDefinitionValidator;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules\Concerns\DocumentsConditionsAndActions;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// Corpo de POST /categorization/apply: ids pendentes + categoria, com uma
// regra opcional (mesmo formato de /rules, sem "actions" — a ação é sempre
// set_category = category_id, calculada aqui). As checagens que dependem de
// consultar o banco (direção misturada, tipo da categoria x direção,
// condições.*) ficam no after(), na mesma ordem em que fariam sentido para o
// usuário corrigir.
final class ApplyCategorizationRequest extends ApiRequest
{
    use DocumentsConditionsAndActions;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ownTransaction = Rule::exists('transactions', 'id')->where('user_id', $this->userId());

        return [
            'transaction_ids' => ['required', 'array', 'min:1'],
            'transaction_ids.*' => ['required', 'integer', 'distinct', $ownTransaction],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('user_id', $this->userId())],
            'rule' => ['sometimes', 'array'],
            'rule.name' => ['required_with:rule', 'string', 'max:80'],
            'rule.match' => ['required_with:rule', 'in:all,any'],
            'rule.conditions' => ['required_with:rule', 'array', 'min:1'],
            ...self::conditionItemRules('rule.conditions.*'),
            'rule.apply_to_pending' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $direction = $this->checkDirection($validator);

            if ($direction !== null) {
                $this->checkCategory($validator, $direction);
            }

            if ($validator->errors()->isEmpty() && is_array($this->input('rule'))) {
                $this->checkRuleConditions($validator);
            }
        }];
    }

    private function checkDirection(Validator $validator): ?Direction
    {
        /** @var list<int> $ids */
        $ids = $this->input('transaction_ids');

        $directions = Transaction::query()->whereIn('id', $ids)->get()
            ->map(fn (Transaction $transaction) => $transaction->direction)
            ->unique();

        if ($directions->count() > 1) {
            $validator->errors()->add('transaction_ids', 'Selecione lançamentos de uma única direção (entrada ou saída).');

            return null;
        }

        /** @var Direction $direction */
        $direction = $directions->first();

        return $direction;
    }

    private function checkCategory(Validator $validator, Direction $direction): void
    {
        $category = Category::query()->whereKey((int) $this->input('category_id'))->first();

        if ($category === null) {
            return;
        }

        if ($category->is_archived) {
            $validator->errors()->add('category_id', 'Categoria arquivada.');

            return;
        }

        if ($category->is_transfer) {
            return;
        }

        $expectedKind = $direction === Direction::In ? CategoryKind::Income : CategoryKind::Expense;

        if ($category->kind !== $expectedKind) {
            $validator->errors()->add('category_id', 'Categoria incompatível com a direção dos lançamentos selecionados.');
        }
    }

    /**
     * Mesma validação de forma de /rules (RuleDefinitionValidator), com
     * "actions" sintetizado a partir de category_id — essa ação em si nunca
     * gera erro (category_id já foi validado acima), então só os erros de
     * "match"/"conditions" importam aqui.
     */
    private function checkRuleConditions(Validator $validator): void
    {
        $definition = [
            'match' => $this->input('rule.match'),
            'conditions' => $this->input('rule.conditions'),
            'actions' => [['type' => 'set_category', 'category_id' => (int) $this->input('category_id')]],
        ];

        foreach (RuleDefinitionValidator::errors($definition) as $path => $message) {
            if ($path === 'match' || str_starts_with($path, 'conditions')) {
                $validator->errors()->add("rule.{$path}", $message);
            }
        }
    }
}
