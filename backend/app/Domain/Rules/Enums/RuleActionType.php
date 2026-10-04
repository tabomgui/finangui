<?php

namespace App\Domain\Rules\Enums;

enum RuleActionType: string
{
    case SetCategory = 'set_category';
    case SetDescription = 'set_description';
    case SetPayee = 'set_payee';
    case AddTag = 'add_tag';
    case Ignore = 'ignore';
}
