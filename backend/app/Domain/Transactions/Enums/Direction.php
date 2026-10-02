<?php

namespace App\Domain\Transactions\Enums;

enum Direction: string
{
    case In = 'in';
    case Out = 'out';

    public function opposite(): self
    {
        return $this === self::In ? self::Out : self::In;
    }
}
