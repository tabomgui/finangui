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

        $results = array_map(fn (mixed $node): bool => self::node($node, $subject), $conditions);

        return $match === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
    }

    /**
     * Um nó de condições: um grupo (tem a chave "conditions") ou uma
     * condição simples. Entrada malformada nunca lança, só não casa.
     */
    private static function node(mixed $node, RuleSubject $subject): bool
    {
        if (! is_array($node)) {
            return false;
        }

        if (isset($node['conditions'])) {
            if (! is_array($node['conditions'])) {
                return false;
            }

            return self::matches(self::str($node['match'] ?? 'all'), array_values($node['conditions']), $subject);
        }

        return self::condition($node, $subject);
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    private static function condition(array $condition, RuleSubject $subject): bool
    {
        $field = RuleField::tryFrom(self::str($condition['field'] ?? ''));
        $op = RuleOperator::tryFrom(self::str($condition['op'] ?? ''));
        $value = $condition['value'] ?? null;

        if ($field === null || $op === null || ! in_array($op, $field->operators(), true)) {
            return false;
        }

        return match ($field) {
            RuleField::Description, RuleField::OriginalDescription, RuleField::Payee, RuleField::Notes => self::text($op, $subject->text($field), self::str($value)),
            RuleField::Amount => self::compare($op, $subject->amount <=> self::toInt($value)),
            RuleField::Date => self::compare($op, strcmp($subject->date, self::str($value)) <=> 0),
            RuleField::Direction => self::compare($op, $subject->direction === self::str($value) ? 0 : 1),
            RuleField::AccountId => self::compare($op, $subject->accountId === self::toInt($value) ? 0 : 1),
        };
    }

    private static function text(RuleOperator $op, string $haystack, string $value): bool
    {
        if ($op === RuleOperator::Regex) {
            return self::regexMatches($value, $haystack);
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
     * Limita o backtracking da PCRE enquanto avalia, para um padrão
     * patológico (ReDoS) não travar a aplicação; restaura o ini depois.
     */
    private static function regexMatches(string $value, string $haystack): bool
    {
        $previous = ini_set('pcre.backtrack_limit', '100000');

        try {
            return @preg_match(self::pattern($value), $haystack) === 1;
        } finally {
            if ($previous !== false) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }
    }

    /**
     * Padrão sem acento (o texto comparado também não tem), delimitado com
     * um caractere que não precisa de escape no uso comum. Só escapa um "~"
     * que ainda não estava escapado, para não quebrar um "\~" que o próprio
     * usuário já tenha escrito no padrão.
     */
    public static function pattern(string $value): string
    {
        $ascii = Str::ascii($value);
        $escaped = preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)~/', '$1\~', $ascii) ?? $ascii;

        return '~'.$escaped.'~iu';
    }

    /**
     * Coerção defensiva: entrada malformada (array, objeto) nunca deve
     * lançar nem gerar warning de conversão; só não casa.
     */
    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function toInt(mixed $value): int
    {
        return is_object($value) ? 0 : (int) $value;
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
