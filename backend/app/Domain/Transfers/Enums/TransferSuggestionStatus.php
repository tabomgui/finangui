<?php

namespace App\Domain\Transfers\Enums;

enum TransferSuggestionStatus: string
{
    case Pending = 'pending';
    case Dismissed = 'dismissed';
}
