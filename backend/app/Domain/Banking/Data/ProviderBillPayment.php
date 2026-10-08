<?php

namespace App\Domain\Banking\Data;

/**
 * Pagamento informado pelo banco dentro de uma fatura (GET /bills,
 * `payments[]`): usado por App\Domain\Banking\Support\CardPaymentMatcher
 * para casar um crédito do cartão com o pagamento que o banco já sabe que
 * quitou aquela fatura. A soma dos pagamentos de cada fatura (atribuindo o
 * mesmo `id` repetido em mais de uma fatura só à preferida — ver
 * CardPaymentMatcher::preferredBillForPayment()) é persistida em
 * App\Domain\Cards\Models\CardStatement::$reported_paid por
 * App\Domain\Banking\Actions\SyncBills; este DTO em si nunca é persistido.
 */
final readonly class ProviderBillPayment
{
    /**
     * @param  string  $date  "YYYY-MM-DD"
     */
    public function __construct(
        public string $id,
        public string $date,
        public int $amountCents,
    ) {}
}
