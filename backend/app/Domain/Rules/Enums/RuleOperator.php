<?php

namespace App\Domain\Rules\Enums;

enum RuleOperator: string
{
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Regex = 'regex';
    case Gt = 'gt';
    case Gte = 'gte';
    case Lt = 'lt';
    case Lte = 'lte';
}
