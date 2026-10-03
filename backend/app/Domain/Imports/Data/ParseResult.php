<?php

namespace App\Domain\Imports\Data;

/**
 * Saída de um parser: linhas válidas e, separadamente, as que falharam
 * (data/valor ilegível, valor zero), com o número da linha e o motivo.
 * `unrecognized` é explícito (em vez de inferir pelo texto de uma falha):
 * true quando o parser não achou nada que reconhecesse como o próprio
 * formato (cabeçalho ausente, ou — no OFX — nenhum bloco <STMTTRN>), o que
 * também é o caso quando um formato forçado não bate com o conteúdo.
 */
final readonly class ParseResult
{
    /**
     * @param  list<ParsedRow>  $rows
     * @param  list<array{line: int, reason: string}>  $failed
     */
    public function __construct(
        public array $rows,
        public array $failed,
        public bool $unrecognized = false,
    ) {}
}
