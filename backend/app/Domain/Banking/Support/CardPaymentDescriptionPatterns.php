<?php

namespace App\Domain\Banking\Support;

use App\Domain\Rules\Support\TextNormalizer;

/**
 * Lista única e configurável de padrões de descrição de pagamento de fatura,
 * usada por App\Domain\Banking\Support\CardPaymentMatcher quando a fatura do
 * banco não traz `payments[]` — e por App\Domain\Transactions\Actions\UpdateTransaction
 * para reconhecer na hora um crédito que o usuário acabou de deixar de
 * ignorar. Comparação sem acento e sem caixa (TextNormalizer::normalize(),
 * maiúsculas ASCII), com `-`/`.` normalizados para espaço antes de comparar
 * (ex.: "pagto-debito.automatico" ainda bate com "PAGTO DEBITO AUTOMATICO").
 * Uma descrição com "estorno" nunca é pagamento, mesmo que também contenha
 * um dos padrões abaixo (ex.: "estorno pagamento recebido" — o banco
 * desfazendo um pagamento que tinha reconhecido antes).
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
        'PAGAMENTO ON LINE',
        'PAGAMENTO ONLINE',
        'DEB AUT PARCIAL',
        'DEBITO AUTOMATICO PARCIAL',
    ];

    private const EXCLUDED = 'ESTORNO';

    public static function matches(string $description): bool
    {
        $normalized = self::normalize($description);

        if (str_contains($normalized, self::EXCLUDED)) {
            return false;
        }

        foreach (self::PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $description): string
    {
        $dashesAndDotsAsSpaces = str_replace(['-', '.'], ' ', TextNormalizer::normalize($description));

        return trim((string) preg_replace('/\s+/', ' ', $dashesAndDotsAsSpaces));
    }
}
