<?php

namespace App\Domain\Cards\Data;

use Carbon\CarbonImmutable;

final readonly class StatementDates
{
    public function __construct(
        public CarbonImmutable $closingDate,
        public CarbonImmutable $dueDate,
    ) {}
}
