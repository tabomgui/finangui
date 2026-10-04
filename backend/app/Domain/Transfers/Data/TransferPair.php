<?php

namespace App\Domain\Transfers\Data;

final readonly class TransferPair
{
    public function __construct(
        public int $outId,
        public int $inId,
        public float $score,
    ) {}
}
