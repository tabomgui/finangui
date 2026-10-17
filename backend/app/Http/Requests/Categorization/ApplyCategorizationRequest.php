<?php

namespace App\Http\Requests\Categorization;

use App\Domain\Categories\Models\Category;
use App\Domain\Categorization\Support\CategoryDirection;
use App\Domain\Rules\Support\RuleConditionOwnershipValidator;
use App\Domain\Rules\Support\RuleDefinitionValidator;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Rules\Concerns\DocumentsConditionsAndActions;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// Corpo de POST /categorization/apply: ids pendentes + categoria, com uma
// regra opcional (mesmo formato de /rules, sem "actions" — a ação é sempre
// set_category = category_id, calculada aqui). As checagens que dependem de
// consultar o banco (existência/dono dos ids, direção misturada, tipo da
// categoria x direção, condições.*) ficam no after(), na mesma ordem em que
// fariam sentido para o usuário corrigir.
final class ApplyCategorizationRequest extends ApiRequest
{
    use DocumentsConditionsAndActions;

    private ?Direction $direction = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Dono de cada id é conferido em checkDirection() (um único
            // SELECT, ver ali) — mandar um "exists" por item em até 5000
            // ids seria uma query a mais por lançamento selecionado.
            'transaction_ids' => ['required', 'array', 'min:1', 'max:5000'],
            'transaction_ids.*' => ['required', 'integer', 'distinct'],
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
     * Direção já conferida como única em checkDirection() — cobre todos os
     * ids enviados, inclusive os que acabam "skipped" (ex.: já categorizados
     * por outro caminho): o apply precisa dela mesmo quando nenhum id segue
     * pendente, para nunca aplicar a regra sem filtro de direção. Só chame
     * depois de validated() não ter lançado (direction() nunca fica nula
     * numa requisição que passou da validação).
     */
    public function direction(): Direction
    {
        assert($this->direction !== null);

        return $this->direction;
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

    /**
     * Um único SELECT (direction, chaveado por id) para o conjunto inteiro
     * de ids — nunca um "exists" por item nem o model inteiro (sem "raw"):
     * id ausente do mapa (não existe ou é de outro usuário — a consulta já
     * escopa por dono, ver App\Models\Concerns\BelongsToUser) rende um erro
     * em "transaction_ids.{i}"; a direção vem do mesmo mapa.
     */
    private function checkDirection(Validator $validator): ?Direction
    {
        /** @var list<int> $ids */
        $ids = $this->input('transaction_ids');

        /** @var Collection<int, Direction> $directionsById */
        $directionsById = Transaction::query()->whereIn('id', $ids)->pluck('direction', 'id');

        foreach ($ids as $i => $id) {
            if (! $directionsById->has($id)) {
                $validator->errors()->add("transaction_ids.{$i}", 'Lançamento não encontrado.');
            }
        }

        if ($validator->errors()->isNotEmpty()) {
            return null;
        }

        $directions = $directionsById->unique();

        if ($directions->count() > 1) {
            $validator->errors()->add('transaction_ids', 'Selecione lançamentos de uma única direção (entrada ou saída).');

            return null;
        }

        /** @var Direction $direction */
        $direction = $directions->first();

        $this->direction = $direction;

        return $direction;
    }

    private function checkCategory(Validator $validator, Direction $direction): void
    {
        $category = Category::query()->with('parent:id,is_transfer')->whereKey((int) $this->input('category_id'))->first();

        if ($category === null) {
            return;
        }

        if ($category->is_archived) {
            $validator->errors()->add('category_id', 'Categoria arquivada.');

            return;
        }

        if (! CategoryDirection::accepts($category, $direction)) {
            $validator->errors()->add('category_id', 'Categoria incompatível com a direção dos lançamentos selecionados.');
        }
    }

    /**
     * Mesma validação de forma de /rules (RuleDefinitionValidator), com
     * "actions" sintetizado a partir de category_id — essa ação em si nunca
     * gera erro (category_id já foi validado acima), então só os erros de
     * "match"/"conditions" importam aqui. Só confere dono de account_id
     * (RuleConditionOwnershipValidator, a mesma de /rules) quando a forma já
     * está certa, senão um índice inválido (ex.: grupo malformado) poderia
     * gerar os dois erros sobre o mesmo caminho.
     */
    private function checkRuleConditions(Validator $validator): void
    {
        $definition = [
            'match' => $this->input('rule.match'),
            'conditions' => $this->input('rule.conditions'),
            'actions' => [['type' => 'set_category', 'category_id' => (int) $this->input('category_id')]],
        ];

        $hasShapeError = false;

        foreach (RuleDefinitionValidator::errors($definition) as $path => $message) {
            if ($path === 'match' || str_starts_with($path, 'conditions')) {
                $validator->errors()->add("rule.{$path}", $message);
                $hasShapeError = true;
            }
        }

        if ($hasShapeError) {
            return;
        }

        /** @var list<array<string, mixed>> $conditions */
        $conditions = array_values($this->input('rule.conditions'));

        foreach (RuleConditionOwnershipValidator::errors($conditions, 'rule.conditions') as $path => $message) {
            $validator->errors()->add($path, $message);
        }
    }
}
