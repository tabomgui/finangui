<?php

namespace App\Domain\Banking\Support;

/**
 * Resultado detalhado de App\Domain\Banking\Support\CardPaymentMatcher::matchDetailed() —
 * `decisions` é o mesmo que match() devolve; `gatedIds` são candidatos
 * reconhecidos pelo padrão de descrição, sem vaga/âncora do banco, mas
 * travados por hasReportedPaymentNear() (algum payments[] do lote cobre a
 * mesma data, de outro valor) — nunca aparecem em `decisions`, e
 * App\Domain\Banking\Actions\ReconcileCardPayments nunca os reseta: ficam
 * exatamente como estão até uma passagem futura decidir algo sobre eles.
 */
final readonly class CardPaymentMatchResult
{
    /**
     * @param  list<CardPaymentDecision>  $decisions
     * @param  array<int, true>  $gatedIds
     */
    public function __construct(
        public array $decisions,
        public array $gatedIds,
    ) {}
}
