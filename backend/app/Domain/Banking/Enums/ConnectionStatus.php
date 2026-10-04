<?php

namespace App\Domain\Banking\Enums;

enum ConnectionStatus: string
{
    case PendingLink = 'pending_link';
    case Active = 'active';
    case NeedsReauth = 'needs_reauth';
    case Error = 'error';
}
