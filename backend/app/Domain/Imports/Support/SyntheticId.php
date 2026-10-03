<?php

namespace App\Domain\Imports\Support;

use App\Domain\Imports\Enums\ImportFormat;
use App\Domain\Rules\Support\TextNormalizer;
use App\Domain\Transactions\Enums\Direction;

/**
 * Id sintético para linhas de formatos sem identificador próprio: a mesma
 * combinação (formato, data, valor, direção, descrição normalizada e
 * ocorrência dentro do arquivo) sempre gera o mesmo id, então reimportar o
 * mesmo arquivo (ou outro que cubra o mesmo período) não duplica.
 */
final class SyntheticId
{
    public static function for(
        ImportFormat $format,
        string $date,
        int $cents,
        Direction $direction,
        string $description,
        int $occurrence,
    ): string {
        $key = implode('|', [
            $format->value,
            $date,
            (string) $cents,
            $direction->value,
            TextNormalizer::key($description),
            (string) $occurrence,
        ]);

        return 'h:'.sha1($key);
    }
}
