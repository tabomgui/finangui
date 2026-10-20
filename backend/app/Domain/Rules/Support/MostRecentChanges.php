<?php

namespace App\Domain\Rules\Support;

use App\Domain\Rules\Data\CategoryChange;

/**
 * Guarda, de um scan em qualquer ordem, só as $limit mudanças mais
 * recentes pela data do lançamento (id desempata), sem manter o scan
 * inteiro na memória: acumula até o dobro do limite e então corta.
 */
final class MostRecentChanges
{
    /** @var list<CategoryChange> */
    private array $changes = [];

    public function __construct(
        private readonly int $limit,
    ) {}

    public function add(CategoryChange $change): void
    {
        $this->changes[] = $change;

        if (count($this->changes) >= 2 * $this->limit) {
            $this->trim();
        }
    }

    /**
     * Mais recentes primeiro.
     *
     * @return list<CategoryChange>
     */
    public function all(): array
    {
        $this->trim();

        return $this->changes;
    }

    private function trim(): void
    {
        usort($this->changes, fn (CategoryChange $a, CategoryChange $b): int => self::key($b) <=> self::key($a));
        $this->changes = array_slice($this->changes, 0, $this->limit);
    }

    /**
     * @return array{string, int}
     */
    private static function key(CategoryChange $change): array
    {
        return [$change->transaction->date->toDateString(), $change->transaction->id];
    }
}
