<?php

namespace App\Domain\Rules\Enums;

/**
 * Grupos da revisão de todas as regras ativas (ver
 * App\Domain\Rules\Queries\ReviewRules).
 */
enum ReviewGroup: string
{
    // Sem categoria; as regras dão uma.
    case Fill = 'fill';

    // Categoria automática (histórico, banco ou regra) diferente da que as
    // regras dão hoje.
    case Conflict = 'conflict';

    // Categorizado por uma regra que não se aplica mais, e nenhuma regra
    // ativa dá categoria: volta a ficar sem categoria.
    case Stale = 'stale';

    // Categorizado à mão com uma categoria diferente da que as regras dão.
    // Só informativo: nunca muda.
    case Manual = 'manual';

    public function applicable(): bool
    {
        return $this !== self::Manual;
    }
}
