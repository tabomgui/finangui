<?php

namespace App\Domain\Banking\Data;

/**
 * Pagamento informado pelo banco dentro de uma fatura (GET /bills,
 * `payments[]`): usado por App\Domain\Banking\Support\CardPaymentMatcher
 * para casar um crédito do cartão com o pagamento que o banco já sabe que
 * quitou aquela fatura — nunca persistido, só usado durante a reconciliação.
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
