<?php

namespace App\Domain\Rules\Enums;

/**
 * Grupos da reconciliação de uma regra recém-editada (ver
 * App\Domain\Rules\Queries\ReconcileRule): o que mudaria nos lançamentos
 * por causa da edição.
 */
enum ReconcileGroup: string
{
    // Categorizado por esta regra, ainda casa, mas a categoria dela mudou.
    case Recategorize = 'recategorize';

    // Sem categoria e agora casa: recebe a categoria da regra.
    case Fill = 'fill';

    // Categorizado por esta regra, que não casa mais (ou não define mais
    // categoria): volta a ficar sem categoria.
    case Clear = 'clear';
}
