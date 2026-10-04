<?php

namespace App\Domain\Imports\Enums;

enum ImportBatchStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Reverted = 'reverted';
}
