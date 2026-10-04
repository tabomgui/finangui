<?php

namespace App\Domain\Banking\Data;

/**
 * Uma conta do banco ainda não vinculada, junto com a sugestão de vínculo
 * calculada por App\Domain\Banking\Actions\CreateConnection — usada só
 * como o `$resource` de App\Http\Resources\ProviderAccountResource (ver lá
 * o porquê de um tipo próprio em vez de um array: Scramble só infere os
 * tipos do JsonResource por reflexão de uma classe real, nunca pela forma
 * de um array).
 */
final readonly class ProviderAccountSuggestion
{
    public function __construct(
        public ProviderAccount $account,
        public ?int $suggestedAccountId,
    ) {}
}
