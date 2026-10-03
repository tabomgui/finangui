<?php

namespace App\Domain\Rules\Support;

use App\Domain\Rules\Enums\RuleActionType;
use App\Domain\Rules\Enums\RuleField;
use App\Domain\Rules\Enums\RuleOperator;
use DateTimeImmutable;

/**
 * Valida o JSON cru de uma regra (match, conditions, actions). Puro: não
 * confere existência de categoria/tag/conta (fica no request, com escopo de
 * usuário). Devolve erros indexados por caminho, no formato do Laravel
 * (ex.: "conditions.0.value").
 */
final class RuleDefinitionValidator
{
    private const MAX_CONDITIONS = 20;

    private const MAX_ACTIONS = 10;

    private const MAX_TAGS = 5;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function errors(array $input): array
    {
        $errors = [];

        if (! in_array($input['match'] ?? null, ['all', 'any'], true)) {
            $errors['match'] = 'Selecione "all" ou "any".';
        }

        return array_merge(
            $errors,
            self::conditionsErrors($input['conditions'] ?? null),
            self::actionsErrors($input['actions'] ?? null),
        );
    }

    /**
     * @return array<string, string>
     */
    private static function conditionsErrors(mixed $conditions): array
    {
        if (! is_array($conditions) || $conditions === []) {
            return ['conditions' => 'Inclua pelo menos uma condição.'];
        }

        $errors = [];
        $total = 0;

        foreach (array_values($conditions) as $i => $node) {
            [$nodeErrors, $count] = self::node($node, "conditions.{$i}", 0);
            $errors = array_merge($errors, $nodeErrors);
            $total += $count;
        }

        if ($total > self::MAX_CONDITIONS) {
            $errors['conditions'] = 'No máximo '.self::MAX_CONDITIONS.' condições, contando as de dentro dos grupos.';
        }

        return $errors;
    }

    /**
     * @return array{0: array<string, string>, 1: int}
     */
    private static function node(mixed $node, string $path, int $depth): array
    {
        if (! is_array($node)) {
            return [[$path => 'Condição inválida.'], 1];
        }

        if (array_key_exists('conditions', $node)) {
            return self::group($node, $path, $depth);
        }

        return [self::condition($node, $path), 1];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: array<string, string>, 1: int}
     */
    private static function group(array $node, string $path, int $depth): array
    {
        if ($depth > 0) {
            return [[$path => 'Grupos não podem ter outros grupos.'], 0];
        }

        $children = $node['conditions'] ?? null;

        if (! is_array($children) || $children === []) {
            return [["{$path}.conditions" => 'Inclua pelo menos uma condição no grupo.'], 0];
        }

        $match = $node['match'] ?? 'all';
        $errors = in_array($match, ['all', 'any'], true) ? [] : ["{$path}.match" => 'Selecione "all" ou "any".'];
        $total = 0;

        foreach (array_values($children) as $i => $child) {
            [$childErrors, $count] = self::node($child, "{$path}.conditions.{$i}", $depth + 1);
            $errors = array_merge($errors, $childErrors);
            $total += $count;
        }

        return [$errors, $total];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, string>
     */
    private static function condition(array $condition, string $path): array
    {
        $field = RuleField::tryFrom((string) ($condition['field'] ?? ''));

        if ($field === null) {
            return ["{$path}.field" => 'Campo desconhecido.'];
        }

        $op = RuleOperator::tryFrom((string) ($condition['op'] ?? ''));

        if ($op === null || ! in_array($op, $field->operators(), true)) {
            return ["{$path}.op" => 'Operador não permitido para este campo.'];
        }

        $error = self::value($field, $op, $condition['value'] ?? null);

        return $error === null ? [] : ["{$path}.value" => $error];
    }

    private static function value(RuleField $field, RuleOperator $op, mixed $value): ?string
    {
        return match ($field) {
            RuleField::Description, RuleField::OriginalDescription, RuleField::Payee, RuleField::Notes => self::textValue($op, $value),
            RuleField::Amount => self::amountValue($value),
            RuleField::Direction => in_array($value, ['in', 'out'], true) ? null : 'Informe "in" ou "out".',
            RuleField::AccountId => is_int($value) ? null : 'Informe uma conta.',
            RuleField::Date => self::dateValue($value),
        };
    }

    private static function textValue(RuleOperator $op, mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Informe um texto.';
        }

        if (mb_strlen($value) > 200) {
            return 'Texto muito longo (máximo 200 caracteres).';
        }

        if ($op !== RuleOperator::Regex) {
            return null;
        }

        $result = @preg_match(RuleMatcher::pattern($value), '');

        if ($result === false) {
            return 'Expressão regular inválida.';
        }

        return $result === 1 ? 'A expressão regular não pode casar texto vazio.' : null;
    }

    private static function amountValue(mixed $value): ?string
    {
        if (! is_int($value)) {
            return 'Informe um valor inteiro em centavos.';
        }

        return $value < 0 ? 'Informe um valor maior ou igual a zero.' : null;
    }

    private static function dateValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'Informe uma data no formato AAAA-MM-DD.';
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value
            ? null
            : 'Informe uma data válida no formato AAAA-MM-DD.';
    }

    /**
     * @return array<string, string>
     */
    private static function actionsErrors(mixed $actions): array
    {
        if (! is_array($actions) || $actions === []) {
            return ['actions' => 'Inclua pelo menos uma ação.'];
        }

        $errors = [];
        $seenTypes = [];
        $seenTags = [];

        foreach (array_values($actions) as $i => $action) {
            $errors = array_merge($errors, self::action($action, "actions.{$i}", $seenTypes, $seenTags));
        }

        if (count($actions) > self::MAX_ACTIONS) {
            $errors['actions'] = 'No máximo '.self::MAX_ACTIONS.' ações.';
        }

        return $errors;
    }

    /**
     * @param  list<RuleActionType>  $seenTypes
     * @param  list<int>  $seenTags
     * @return array<string, string>
     */
    private static function action(mixed $action, string $path, array &$seenTypes, array &$seenTags): array
    {
        if (! is_array($action)) {
            return [$path => 'Ação inválida.'];
        }

        $type = RuleActionType::tryFrom((string) ($action['type'] ?? ''));

        if ($type === null) {
            return ["{$path}.type" => 'Tipo de ação desconhecido.'];
        }

        if ($type !== RuleActionType::AddTag) {
            if (in_array($type, $seenTypes, true)) {
                return ["{$path}.type" => 'Use no máximo uma ação deste tipo.'];
            }
            $seenTypes[] = $type;
        }

        return match ($type) {
            RuleActionType::SetCategory => is_int($action['category_id'] ?? null) ? [] : ["{$path}.category_id" => 'Informe a categoria.'],
            RuleActionType::SetDescription, RuleActionType::SetPayee => self::textAction($action['value'] ?? null, $path),
            RuleActionType::AddTag => self::tagAction($action['tag_id'] ?? null, $path, $seenTags),
            RuleActionType::Ignore => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private static function textAction(mixed $value, string $path): array
    {
        if (! is_string($value) || trim($value) === '') {
            return ["{$path}.value" => 'Informe um texto.'];
        }

        return mb_strlen($value) > 255
            ? ["{$path}.value" => 'Texto muito longo (máximo 255 caracteres).']
            : [];
    }

    /**
     * @param  list<int>  $seenTags
     * @return array<string, string>
     */
    private static function tagAction(mixed $tagId, string $path, array &$seenTags): array
    {
        if (! is_int($tagId)) {
            return ["{$path}.tag_id" => 'Informe a tag.'];
        }

        if (in_array($tagId, $seenTags, true)) {
            return ["{$path}.tag_id" => 'Tag repetida.'];
        }

        $seenTags[] = $tagId;

        return count($seenTags) > self::MAX_TAGS
            ? ["{$path}.tag_id" => 'No máximo '.self::MAX_TAGS.' tags por regra.']
            : [];
    }
}
