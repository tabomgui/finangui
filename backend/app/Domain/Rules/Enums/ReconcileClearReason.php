<?php

namespace App\Domain\Rules\Enums;

/**
 * Por que o grupo "clear" da reconciliação tira a categoria: a regra não
 * casa mais com esses lançamentos, deixou de ter a ação set_category, ou
 * está desativada.
 */
enum ReconcileClearReason: string
{
    case NoLongerMatches = 'no_longer_matches';
    case NoCategoryAction = 'no_category_action';
    case Inactive = 'inactive';
}
