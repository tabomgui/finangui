<?php

namespace App\Domain\Banking\Support;

/**
 * Entrada (crédito) do cartão candidata a pagamento de fatura, já traduzida
 * para fora do Eloquent — ver App\Domain\Banking\Support\CardPaymentMatcher
 * (puro, sem banco) e App\Domain\Banking\Actions\ReconcileCardPayments (monta
 * a lista a partir das transações de verdade). `isProviderSourced` e
 * `isPending` só importam para a deduplicação: só uma entrada vinda do banco
 * (source pluggy) pode ser marcada como duplicata, e um lançamento já
 * lançado (posted) é preferido a um ainda pendente ao escolher quem fica.
 * `isLocked` (card_payment_locked — o usuário editou is_ignored à mão) vale
 * como uma referência fixa, igual a uma perna de transferência: sempre
 * reivindica sua vaga primeiro e nunca é candidata a duplicata.
 */
final readonly class CardPaymentCandidate
{
    /**
     * @param  string  $date  "YYYY-MM-DD"
     */
    public function __construct(
        public int $id,
        public int $amountCents,
        public string $date,
        public string $description,
        public bool $isTransferLeg,
        public bool $isProviderSourced,
        public bool $isPending,
        public bool $isLocked = false,
    ) {}
}
