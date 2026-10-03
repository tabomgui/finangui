<?php

namespace App\Domain\Cards\Support;

use App\Domain\Cards\Models\CardStatement;
use Carbon\CarbonImmutable;

/**
 * Mantém as faturas de um cartão ordenadas e sem sobreposição entre
 * vizinhas por closing_date — a regra usada tanto pela edição manual
 * (App\Domain\Cards\Actions\UpdateStatement, que detalha por campo com
 * ValidationException) quanto pelo sync bancário
 * (App\Domain\Banking\Actions\SyncBills, que só aplica a data nova quando
 * ela cabe, sem avisar o usuário — se não cabe, a fatura fica com as datas
 * antigas).
 */
final class StatementOrdering
{
    public const MAX_SPAN_DAYS = 40;

    /**
     * @return array{previous: ?CardStatement, next: ?CardStatement}
     */
    public static function neighbors(CardStatement $statement): array
    {
        $others = CardStatement::query()->where('account_id', $statement->account_id)->where('id', '!=', $statement->id);

        return [
            'previous' => (clone $others)->where('closing_date', '<', $statement->closing_date)->orderByDesc('closing_date')->first(),
            'next' => (clone $others)->where('closing_date', '>', $statement->closing_date)->orderBy('closing_date')->first(),
        ];
    }

    /**
     * Como neighbors(), mas para uma fatura que ainda não existe (nenhuma
     * linha para excluir por id) — usado por SyncBills ao considerar criar
     * uma fatura nova para uma conta.
     *
     * @return array{previous: ?CardStatement, next: ?CardStatement}
     */
    public static function neighborsForAccount(int $accountId, CarbonImmutable $closing): array
    {
        $others = CardStatement::query()->where('account_id', $accountId);

        return [
            'previous' => (clone $others)->where('closing_date', '<', $closing)->orderByDesc('closing_date')->first(),
            'next' => (clone $others)->where('closing_date', '>', $closing)->orderBy('closing_date')->first(),
        ];
    }

    public static function dueAfterClosing(CarbonImmutable $closing, CarbonImmutable $due): bool
    {
        return $due->greaterThan($closing);
    }

    public static function closingFitsNeighbors(?CardStatement $previous, ?CardStatement $next, CarbonImmutable $closing): bool
    {
        if ($previous !== null && $closing->lessThanOrEqualTo($previous->closing_date)) {
            return false;
        }

        return $next === null || $closing->lessThan($next->closing_date);
    }

    public static function dueFitsNeighbors(?CardStatement $previous, ?CardStatement $next, CarbonImmutable $due): bool
    {
        if ($previous !== null && $due->lessThanOrEqualTo($previous->due_date)) {
            return false;
        }

        return $next === null || $due->lessThan($next->due_date);
    }

    public static function withinMaxSpan(CarbonImmutable $closing, CarbonImmutable $due): bool
    {
        return ! $due->greaterThan($closing->addDays(self::MAX_SPAN_DAYS));
    }

    /**
     * Confere as quatro regras de uma vez, sem levantar erro — para quem só
     * precisa decidir se aplica a data nova ou ignora (SyncBills).
     */
    public static function fits(?CardStatement $previous, ?CardStatement $next, CarbonImmutable $closing, CarbonImmutable $due): bool
    {
        return self::dueAfterClosing($closing, $due)
            && self::closingFitsNeighbors($previous, $next, $closing)
            && self::dueFitsNeighbors($previous, $next, $due)
            && self::withinMaxSpan($closing, $due);
    }
}
