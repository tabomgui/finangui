<?php

namespace App\Http\Requests\Rules\Concerns;

use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Enums\RuleActionType;
use App\Domain\Rules\Support\RuleConditionOwnershipValidator;
use App\Domain\Rules\Support\RuleDefinitionValidator;
use App\Domain\Tags\Models\Tag;
use Illuminate\Validation\Validator;

// Compartilhado entre StoreRuleRequest e UpdateRuleRequest: roda o
// RuleDefinitionValidator (puro, não confere dono) sobre match/conditions/
// actions e, só se não houver erro nenhum ainda, confere que conta/categoria/
// tag referenciadas pertencem ao usuário autenticado.
trait ValidatesRuleDefinition
{
    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->shouldValidateDefinition()) {
                return;
            }

            $definition = $this->definitionInput();

            foreach (RuleDefinitionValidator::errors($definition) as $path => $message) {
                $validator->errors()->add($path, $message);
            }

            if ($validator->errors()->isEmpty()) {
                $this->checkOwnership($validator, $definition);
            }
        }];
    }

    /**
     * No Store sempre true (match/conditions/actions são obrigatórios); no
     * Update, só quando pelo menos uma das três chaves foi enviada — senão
     * um PATCH que só muda `name`/`is_active` ficaria revalidando referências
     * que já eram válidas desde que a regra foi criada.
     */
    protected function shouldValidateDefinition(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected function definitionInput(): array;

    /**
     * @param  array<string, mixed>  $definition
     */
    private function checkOwnership(Validator $validator, array $definition): void
    {
        $conditions = is_array($definition['conditions'] ?? null) ? $definition['conditions'] : [];
        $actions = is_array($definition['actions'] ?? null) ? $definition['actions'] : [];

        $this->checkConditions($validator, array_values($conditions), 'conditions');
        $this->checkActions($validator, array_values($actions), 'actions');
    }

    /**
     * @param  list<mixed>  $conditions
     */
    private function checkConditions(Validator $validator, array $conditions, string $path): void
    {
        foreach (RuleConditionOwnershipValidator::errors($conditions, $path) as $errorPath => $message) {
            $validator->errors()->add($errorPath, $message);
        }
    }

    /**
     * @param  list<mixed>  $actions
     */
    private function checkActions(Validator $validator, array $actions, string $path): void
    {
        foreach ($actions as $i => $action) {
            if (! is_array($action)) {
                continue;
            }

            $itemPath = "{$path}.{$i}";
            $type = RuleActionType::tryFrom(is_string($action['type'] ?? null) ? $action['type'] : '');

            if ($type === RuleActionType::SetCategory) {
                $categoryId = $action['category_id'] ?? null;
                if (is_int($categoryId)) {
                    $category = Category::query()->whereKey($categoryId)->first();

                    if ($category === null) {
                        $validator->errors()->add("{$itemPath}.category_id", 'Categoria não encontrada.');
                    } elseif ($category->is_archived) {
                        $validator->errors()->add("{$itemPath}.category_id", 'Categoria arquivada.');
                    }
                }
            } elseif ($type === RuleActionType::AddTag) {
                $tagId = $action['tag_id'] ?? null;
                if (is_int($tagId) && ! Tag::query()->whereKey($tagId)->exists()) {
                    $validator->errors()->add("{$itemPath}.tag_id", 'Tag não encontrada.');
                }
            }
        }
    }
}
