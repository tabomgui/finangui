<?php

namespace App\Domain\Imports\Data;

/**
 * Saída de um parser: linhas válidas e, separadamente, as que falharam
 * (data/valor ilegível, valor zero), com o número da linha e o motivo.
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
    ) {}
}
