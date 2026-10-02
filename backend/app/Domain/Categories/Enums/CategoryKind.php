<?php

namespace App\Domain\Categories\Enums;

enum CategoryKind: string
{
    case Income = 'income';
    case Expense = 'expense';
}
