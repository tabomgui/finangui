<?php

namespace App\Domain\Imports\Data;

use App\Domain\Transactions\Enums\Direction;

/**
 * Linha normalizada por um parser, antes de qualquer decisão de ingestão.
 */
final readonly class ParsedRow
{
    /**
     * @param  int  $line  linha no arquivo (1-based), para mensagens
     * @param  string  $date  "YYYY-MM-DD"
     * @param  int  $amount  centavos, sempre positivo
     * @param  array{number: int, total: int}|null  $installment
     * @param  array<string, mixed>  $meta  dados que só a sincronização bancária preenche (`bill_id`, `provider_category` = `{name, parent}`); vazio para linhas de arquivo
     */
    public function __construct(
        public int $line,
        public string $date,
        public int $amount,
        public Direction $direction,
        public string $description,
        public string $externalId,
        public ?array $installment = null,
        public bool $pending = false,
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'date' => $this->date,
            'amount' => $this->amount,
            'direction' => $this->direction->value,
            'description' => $this->description,
            'external_id' => $this->externalId,
            'installment' => $this->installment,
            'pending' => $this->pending,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{number: int, total: int}|null $installment */
        $installment = $data['installment'] ?? null;
        /** @var array<string, mixed> $meta */
        $meta = $data['meta'] ?? [];

        return new self(
            line: (int) $data['line'],
            date: (string) $data['date'],
            amount: (int) $data['amount'],
            direction: Direction::from($data['direction']),
            description: (string) $data['description'],
            externalId: (string) $data['external_id'],
            installment: $installment,
            pending: (bool) ($data['pending'] ?? false),
            meta: $meta,
        );
    }
}
