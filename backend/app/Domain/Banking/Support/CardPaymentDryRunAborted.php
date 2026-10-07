<?php

namespace App\Domain\Banking\Support;

use RuntimeException;

/**
 * Lançada de propósito de dentro da transação de App\Domain\Banking\Actions\ReconcileCardPayments
 * quando chamada com $dryRun — o DB::transaction() em volta já desfaz a
 * transação ao ver qualquer exceção (ver ManagesTransactions::transaction()),
 * então isto é só o jeito de sair carregando os contadores calculados sem
 * precisar de uma segunda transação por fora. Nunca indica um erro de
 * verdade; quem chama com dryRun = true sempre espera e captura esta classe.
 */
final class CardPaymentDryRunAborted extends RuntimeException
{
    /**
     * @param  array{payments: int, duplicates: int, transfers_linked: int}  $counts
     */
    public function __construct(public readonly array $counts)
    {
        parent::__construct('Reconciliação de pagamentos de fatura em modo dry-run: nada foi gravado.');
    }
}
