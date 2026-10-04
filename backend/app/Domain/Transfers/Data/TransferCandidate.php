<?php

namespace App\Domain\Transfers\Data;

use App\Domain\Transactions\Enums\Direction;

/**
 * Lado de uma transferência possível: transação sem transfer_id, candidata a
 * casar com outra. accountName e description já vêm normalizados
 * (TextNormalizer::normalize), para a pontuação comparar texto comparável.
 *
 * `pending` e `manualNonTransferCategory` só importam para decidir se um
 * match mútuo pode ser ligado automaticamente (ver
 * TransferMatcher::canAutoLink()) — nunca entram na pontuação em si.
 */
final readonly class TransferCandidate
{
    public function __construct(
        public int $id,
        public int $accountId,
        public string $accountName,
        public bool $creditCard,
        public string $currency,
        public Direction $direction,
        public int $amount,
        public string $date,
        public string $description,
        public bool $pending = false,
        public bool $manualNonTransferCategory = false,
    ) {}
}
