<?php

namespace App\Domain\Transactions\Enums;

enum TransactionSource: string
{
    case Manual = 'manual';
    case Pluggy = 'pluggy';
    case Csv = 'csv';
    case Ofx = 'ofx';
    case Recurrence = 'recurrence';
    case Installment = 'installment';
}
