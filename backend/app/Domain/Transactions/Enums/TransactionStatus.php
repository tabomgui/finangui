<?php

namespace App\Domain\Transactions\Enums;

enum TransactionStatus: string
{
    case Posted = 'posted';
    case Projected = 'projected';
    case Pending = 'pending';
}
