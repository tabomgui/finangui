<?php

namespace App\Domain\Rules\Support;

use App\Domain\Rules\Enums\RuleField;
use App\Domain\Rules\Enums\RuleOperator;
use DateTimeImmutable;

/**
 * Valida o JSON cru de uma regra (match, conditions, actions). Puro: não
 * confere existência de categoria/tag/conta (fica no request, com escopo de
 * usuário). Devolve erros indexados por caminho, no formato do Laravel
 * (ex.: "conditions.0.value").
 *
 * Entrada malformada (tipo errado em field/op/type/value, lista que na
 * verdade é um array associativo) nunca lança: só gera um erro no caminho
 * certo. As contagens (máximo de condições/regex) saem antes de validar
 * cada item, para uma lista enorme não ser percorrida em vão. A validação
 * de `actions` fica em RuleActionsValidator (mesmas regras de entrada
 * malformada), separada por tamanho: as duas listas não compartilham
 * estado nem precisam uma da outra.
 */
final class RuleDefinitionValidator
{
    private const MAX_CONDITIONS = 20;

    private const MAX_REGEX_CONDITIONS = 5;

    private const INVALID_MATCH_MESSAGE = 'Escolha se todas ou alguma das condições precisam casar.';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function errors(array $input): array
    {
        $errors = [];

        if (! in_array($input['match'] ?? null, ['all', 'any'], true)) {
            $errors['match'] = self::INVALID_MATCH_MESSAGE;
        }

        $errors += self::conditionsErrors($input['conditions'] ?? null);
        $errors += RuleActionsValidator::errors($input['actions'] ?? null);

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private static function conditionsErrors(mixed $conditions): array
    {
        if (! is_array($conditions) || $conditions === []) {
            return ['conditions' => 'Inclua pelo menos uma condição.'];
        }

        if (! array_is_list($conditions)) {
            return ['conditions' => 'A lista de condições está mal formada.'];
        }

        if (count($conditions) > self::MAX_CONDITIONS) {
            return ['conditions' => self::maxConditionsMessage()];
        }

        $errors = [];
        $total = 0;
        $regexCount = 0;

        foreach ($conditions as $i => $node) {
            [$nodeErrors, $count, $regexes] = self::node($node, "conditions.{$i}", 0);
            $errors += $nodeErrors;
            $total += $count;
            $regexCount += $regexes;

            if ($total > self::MAX_CONDITIONS) {
                $errors['conditions'] = self::maxConditionsMessage();

                return $errors;
            }
        }

        if ($regexCount > self::MAX_REGEX_CONDITIONS) {
            $errors['conditions'] = 'No máximo '.self::MAX_REGEX_CONDITIONS.' condições regex por regra.';
        }

        return $errors;
    }

    private static function maxConditionsMessage(): string
    {
        return 'No máximo '.self::MAX_CONDITIONS.' condições, contando as de dentro dos grupos.';
    }

    /**
     * @return array{0: array<string, string>, 1: int, 2: int}
     */
    private static function node(mixed $node, string $path, int $depth): array
    {
        if (! is_array($node)) {
            return [[$path => 'Condição inválida.'], 1, 0];
        }

        if (array_key_exists('conditions', $node)) {
            return self::group($node, $path, $depth);
        }

        [$errors, $isRegex] = self::condition($node, $path);

        return [$errors, 1, $isRegex ? 1 : 0];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{0: array<string, string>, 1: int, 2: int}
     */
    private static function group(array $node, string $path, int $depth): array
    {
        if ($depth > 0) {
            return [[$path => 'Grupos não podem ter outros grupos.'], 0, 0];
        }

        $children = $node['conditions'] ?? null;

        if (! is_array($children) || $children === []) {
            return [["{$path}.conditions" => 'Inclua pelo menos uma condição no grupo.'], 0, 0];
        }

        if (! array_is_list($children)) {
            return [["{$path}.conditions" => 'A lista de condições do grupo está mal formada.'], 0, 0];
        }

        if (count($children) > self::MAX_CONDITIONS) {
            return [["{$path}.conditions" => self::maxConditionsMessage()], count($children), 0];
        }

        $match = $node['match'] ?? 'all';
        $errors = in_array($match, ['all', 'any'], true) ? [] : ["{$path}.match" => self::INVALID_MATCH_MESSAGE];
        $total = 0;
        $regexCount = 0;

        foreach ($children as $i => $child) {
            [$childErrors, $count, $regexes] = self::node($child, "{$path}.conditions.{$i}", $depth + 1);
            $errors += $childErrors;
            $total += $count;
            $regexCount += $regexes;

            if ($total > self::MAX_CONDITIONS) {
                break;
            }
        }

        return [$errors, $total, $regexCount];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{0: array<string, string>, 1: bool}
     */
    private static function condition(array $condition, string $path): array
    {
        $field = RuleField::tryFrom(self::str($condition['field'] ?? ''));

        if ($field === null) {
            return [["{$path}.field" => 'Campo desconhecido.'], false];
        }

        $op = RuleOperator::tryFrom(self::str($condition['op'] ?? ''));

        if ($op === null || ! in_array($op, $field->operators(), true)) {
            return [["{$path}.op" => 'Operador não permitido para este campo.'], false];
        }

        $error = self::value($field, $op, $condition['value'] ?? null);

        return [$error === null ? [] : ["{$path}.value" => $error], $op === RuleOperator::Regex];
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
            return TextNormalizer::normalize($value) === '' ? 'O texto não tem letras ou números comparáveis.' : null;
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
     * Coerção defensiva: entrada malformada (array, objeto) nunca deve
     * lançar nem gerar warning de conversão; só não casa com nenhum enum.
     */
    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
