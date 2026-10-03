<?php

namespace App\Domain\Cards\Enums;

enum StatementStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Partial = 'partial';
    case Paid = 'paid';
}
