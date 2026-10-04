<?php

namespace App\Domain\Imports\Data;

use App\Domain\Imports\Enums\RowOutcome;
use App\Domain\Transactions\Models\Transaction;

/**
 * Destino de uma linha parseada, decidido pelo IngestionPlanner sem gravar
 * nada: usado tanto pela prévia quanto pela confirmação, para garantir que
 * as duas vejam os mesmos números.
 */
final readonly class RowDecision
{
    /**
     * @param  Transaction|null  $transaction  o model já carregado pelo IngestionPlanner (mesma leitura que decidiu o outcome): quem consome a decisão usa este, não refaz a consulta.
     * @param  int|null  $seedIndex  só para `ReplaceInstallment` sem `transaction`: índice (no mesmo array de decisões) da linha deste arquivo que "semeou" a compra — ver ImportedInstallments::classify().
     * @param  bool  $matchedByRecurrence  só para `Adopt`: true quando quem decidiu foi RecurrenceMatcher::bestMatch() (não a adoção comum de lançamento manual) — usado pela prévia para rotular "Casa com lançamento previsto" (ver ImportPreview::matches()).
     */
    public function __construct(
        public ParsedRow $row,
        public RowOutcome $outcome,
        public ?int $transactionId = null,
        public ?Transaction $transaction = null,
        public ?int $seedIndex = null,
        public bool $matchedByRecurrence = false,
    ) {}
}
