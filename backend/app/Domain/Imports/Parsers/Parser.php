<?php

namespace App\Domain\Imports\Parsers;

use App\Domain\Imports\Data\ParseResult;
use App\Domain\Imports\Enums\ImportFormat;

interface Parser
{
    public function format(): ImportFormat;

    /** Reconhece o conteúdo (já normalizado) pelo cabeçalho/estrutura. */
    public function accepts(string $content): bool;

    /**
     * @param  bool  $creditCard  conta de destino é cartão: só então o sufixo de parcela vira installment
     */
    public function parse(string $content, bool $creditCard): ParseResult;
}
