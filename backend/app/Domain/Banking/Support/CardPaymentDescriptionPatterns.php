<?php

namespace App\Domain\Banking\Support;

use App\Domain\Rules\Support\TextNormalizer;

/**
 * Lista única e configurável de padrões de descrição de pagamento de fatura,
 * usada por App\Domain\Banking\Support\CardPaymentMatcher quando a fatura do
 * banco não traz `payments[]` — e por App\Domain\Transactions\Actions\UpdateTransaction
 * para reconhecer na hora um crédito que o usuário acabou de deixar de
 * ignorar. Comparação sem acento e sem caixa (TextNormalizer::normalize(),
 * maiúsculas ASCII).
 */
final class CardPaymentDescriptionPatterns
{
    /** @var list<string> */
    private const PATTERNS = [
        'PAGAMENTO RECEBIDO',
        'PAGTO DEBITO AUTOMATICO',
        'PAGAMENTO DE FATURA',
        'PAGAMENTO FATURA',
        'PGTO',
    ];

    public static function matches(string $description): bool
    {
        $normalized = TextNormalizer::normalize($description);

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
