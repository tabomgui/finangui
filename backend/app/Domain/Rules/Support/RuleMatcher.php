<?php

namespace App\Domain\Rules\Support;

use App\Domain\Rules\Data\RuleSubject;
use App\Domain\Rules\Enums\RuleField;
use App\Domain\Rules\Enums\RuleOperator;
use Illuminate\Support\Str;

/**
 * Decide se as condições de uma regra casam com uma transação. Puro. Espera
 * condições já validadas (RuleDefinitionValidator); entrada malformada não
 * casa em vez de lançar exceção.
 */
final class RuleMatcher
{
    /**
     * @param  list<array<string, mixed>>  $conditions
     */
    public static function matches(string $match, array $conditions, RuleSubject $subject): bool
    {
        if ($conditions === []) {
            return false;
        }

        $results = array_map(
            fn (array $node) => isset($node['conditions'])
                ? self::matches((string) ($node['match'] ?? 'all'), array_values($node['conditions']), $subject)
                : self::condition($node, $subject),
            $conditions,
        );

        return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function condition(array $condition, RuleSubject $subject): bool
    {
        $field = RuleField::tryFrom((string) ($condition['field'] ?? ''));
        $op = RuleOperator::tryFrom((string) ($condition['op'] ?? ''));
        $value = $condition['value'] ?? null;

        if ($field === null || $op === null || ! in_array($op, $field->operators(), true)) {
            return false;
        }

        return match ($field) {
            RuleField::Description, RuleField::OriginalDescription, RuleField::Payee, RuleField::Notes => self::text($op, $subject->text($field), (string) $value),
            RuleField::Amount => self::compare($op, $subject->amount <=> (int) $value),
            RuleField::Date => self::compare($op, strcmp($subject->date, (string) $value) <=> 0),
            RuleField::Direction => self::compare($op, $subject->direction === (string) $value ? 0 : 1),
            RuleField::AccountId => self::compare($op, $subject->accountId === (int) $value ? 0 : 1),
        };
    }

    private static function text(RuleOperator $op, string $haystack, string $value): bool
    {
        if ($op === RuleOperator::Regex) {
            $result = @preg_match(self::pattern($value), $haystack);

            return $result === 1;
        }

        $needle = TextNormalizer::normalize($value);

        return match ($op) {
            RuleOperator::Contains => $needle !== '' && str_contains($haystack, $needle),
            RuleOperator::NotContains => $needle === '' || ! str_contains($haystack, $needle),
            RuleOperator::StartsWith => $needle !== '' && str_starts_with($haystack, $needle),
            RuleOperator::EndsWith => $needle !== '' && str_ends_with($haystack, $needle),
            RuleOperator::Equals => $haystack === $needle,
            RuleOperator::NotEquals => $haystack !== $needle,
            default => false,
        };
    }

    /**
     * Padrão sem acento (o texto comparado também não tem), delimitado com
     * um caractere que não precisa de escape no uso comum.
     */
    public static function pattern(string $value): string
    {
        return '~'.str_replace('~', '\~', Str::ascii($value)).'~iu';
    }

    private static function compare(RuleOperator $op, int $cmp): bool
    {
        return match ($op) {
            RuleOperator::Equals => $cmp === 0,
            RuleOperator::NotEquals => $cmp !== 0,
            RuleOperator::Gt => $cmp > 0,
            RuleOperator::Gte => $cmp >= 0,
            RuleOperator::Lt => $cmp < 0,
            RuleOperator::Lte => $cmp <= 0,
            default => false,
        };
    }
}
