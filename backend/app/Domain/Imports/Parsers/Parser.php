<?php

namespace App\Domain\Imports\Parsers;

use App\Domain\Imports\Data\ParseResult;
use App\Domain\Imports\Enums\ImportFormat;

/**
 * Implementações recebem sempre conteúdo já normalizado (ver
 * App\Domain\Imports\Support\Content::normalize): sem BOM, UTF-8, quebras
 * de linha "\n". Quem só tem o arquivo bruto deve chamar
 * App\Domain\Imports\Support\FormatDetector::parse(), que normaliza uma
 * única vez e decide o formato antes de chamar o parser.
 */
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
