<?php

namespace App\Domain\Accounts\Enums;

/**
 * credit_card entra na fase 2.
 */
enum AccountType: string
{
    case Checking = 'checking';
    case Savings = 'savings';
    case Cash = 'cash';
}
