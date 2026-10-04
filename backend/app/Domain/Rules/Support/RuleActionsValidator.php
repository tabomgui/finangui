<?php

namespace App\Domain\Rules\Support;

use App\Domain\Rules\Enums\RuleActionType;

/**
 * Valida o JSON cru da lista `actions` de uma regra. Puro, nos mesmos termos
 * de RuleDefinitionValidator (de onde foi extraído): não confere existência
 * de categoria/tag (fica no request, com escopo de usuário); entrada
 * malformada nunca lança, só gera um erro no caminho certo.
 */
final class RuleActionsValidator
{
    private const MAX_ACTIONS = 10;

    private const MAX_TAGS = 5;

    /**
     * @return array<string, string>
     */
    public static function errors(mixed $actions): array
    {
        if (! is_array($actions) || $actions === []) {
            return ['actions' => 'Inclua pelo menos uma ação.'];
        }

        if (! array_is_list($actions)) {
            return ['actions' => 'A lista de ações está mal formada.'];
        }

        if (count($actions) > self::MAX_ACTIONS) {
            return ['actions' => 'No máximo '.self::MAX_ACTIONS.' ações.'];
        }

        $errors = [];
        $seenTypes = [];
        $seenTags = [];

        foreach ($actions as $i => $action) {
            $errors += self::action($action, "actions.{$i}", $seenTypes, $seenTags);
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

        $type = RuleActionType::tryFrom(self::str($action['type'] ?? ''));

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
            RuleActionType::SetDescription => self::textAction($action['value'] ?? null, $path, 255),
            RuleActionType::SetPayee => self::textAction($action['value'] ?? null, $path, 120),
            RuleActionType::AddTag => self::tagAction($action['tag_id'] ?? null, $path, $seenTags),
            RuleActionType::Ignore => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private static function textAction(mixed $value, string $path, int $maxLength): array
    {
        if (! is_string($value) || trim($value) === '') {
            return ["{$path}.value" => 'Informe um texto.'];
        }

        return mb_strlen($value) > $maxLength
            ? ["{$path}.value" => "Texto muito longo (máximo {$maxLength} caracteres)."]
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

        if (count($seenTags) >= self::MAX_TAGS) {
            return ["{$path}.tag_id" => 'No máximo '.self::MAX_TAGS.' tags por regra.'];
        }

        $seenTags[] = $tagId;

        return [];
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
