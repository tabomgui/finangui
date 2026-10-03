<?php

namespace App\Domain\Imports\Support;

/**
 * Extrai o sufixo de parcela ("Parcela 2/10", "PARC 02/10", "(2/10)") do fim
 * de uma descrição. Só conta como parcela quando 1 <= número <= total <= 99
 * e total >= 2; caso contrário a descrição volta intacta.
 */
final class InstallmentSuffix
{
    private const MAX_TOTAL = 99;

    private const MIN_TOTAL = 2;

    /**
     * @return array{0: string, 1: array{number: int, total: int}|null}
     */
    public static function extract(string $description): array
    {
        $patterns = [
            '/^(?<desc>.*?)\bparcela\s*(?<number>\d{1,2})\s*\/\s*(?<total>\d{1,2})\s*$/i',
            '/^(?<desc>.*?)\bparc\.?\s*(?<number>\d{1,2})\s*\/\s*(?<total>\d{1,2})\s*$/i',
            '/^(?<desc>.*?)\((?<number>\d{1,2})\/(?<total>\d{1,2})\)\s*$/',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $description, $m)) {
                continue;
            }

            $number = (int) $m['number'];
            $total = (int) $m['total'];

            if ($number < 1 || $total < self::MIN_TOTAL || $number > $total || $total > self::MAX_TOTAL) {
                return [$description, null];
            }

            $clean = trim($m['desc']);
            $clean = rtrim($clean, "- \t");
            $clean = trim($clean);

            return [$clean, ['number' => $number, 'total' => $total]];
        }

        return [$description, null];
    }
}
