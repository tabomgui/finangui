<?php

namespace App\Domain\Imports\Support;

/**
 * Converte um valor monetário de texto para centavos, sem passar por float.
 * Aceita formato brasileiro ("1.234,56", "-50,00", com separador de milhar
 * validado grupo a grupo) e um formato com ponto decimal puro, sem
 * separador de milhar ("1234.56", 1 ou 2 casas — "1.234" com 3 dígitos
 * depois do ponto não é aceito nesse formato, pois seria ambíguo com
 * milhar). O formato americano com vírgula de milhar e ponto decimal
 * ("1,234.56") não é suportado. Um valor começando só com o ponto decimal
 * sem parte inteira (".5") também não é aceito, por ser ambíguo demais
 * nesse ramo (poderia ser milhar truncado).
 */
final class BrazilianNumber
{
    private const MAX_INTEGER_DIGITS = 16;

    private const MAX_CENTS = 1_000_000_000_000_000;

    public static function toCents(string $value): ?int
    {
        $value = str_replace("\u{A0}", '', $value);
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = trim(str_ireplace('r$', '', $value));

        $negative = false;
        if (str_starts_with($value, '-')) {
            $negative = true;
            $value = trim(substr($value, 1));
        } elseif (str_starts_with($value, '+')) {
            $value = trim(substr($value, 1));
        }

        if ($value === '' || ! preg_match('/^[0-9.,]+$/', $value)) {
            return null;
        }

        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastDot > $lastComma) {
                // Formato americano ("1,234.56"): não suportado.
                return null;
            }

            $rawInteger = substr($value, 0, $lastComma);
            $decimalPart = substr($value, $lastComma + 1);

            if (! self::hasValidGrouping($rawInteger)) {
                return null;
            }

            $integerPart = str_replace('.', '', $rawInteger);
        } elseif ($lastComma !== false) {
            $integerPart = substr($value, 0, $lastComma);
            $decimalPart = substr($value, $lastComma + 1);
        } elseif ($lastDot !== false) {
            $decimalPart = substr($value, $lastDot + 1);
            $integerPart = substr($value, 0, $lastDot);

            if ($integerPart === '' || substr_count($value, '.') !== 1 || strlen($decimalPart) < 1 || strlen($decimalPart) > 2) {
                return null;
            }
        } else {
            $integerPart = $value;
            $decimalPart = '';
        }

        if ($integerPart === '') {
            $integerPart = '0';
        }

        if (! ctype_digit($integerPart) || ($decimalPart !== '' && ! ctype_digit($decimalPart)) || strlen($decimalPart) > 2) {
            return null;
        }

        if (strlen($integerPart) > self::MAX_INTEGER_DIGITS) {
            return null;
        }

        $cents = (int) ($integerPart.str_pad($decimalPart, 2, '0'));

        if ($cents > self::MAX_CENTS) {
            return null;
        }

        return $negative ? -$cents : $cents;
    }

    /**
     * A parte inteira de um valor em formato brasileiro ("1.234.567") só é
     * válida se o primeiro grupo tiver de 1 a 3 dígitos e todos os grupos
     * seguintes tiverem exatamente 3 — rejeita "1.2,3" (grupo de 1 dígito
     * no meio) e "1..2,00" (grupo vazio).
     */
    private static function hasValidGrouping(string $rawInteger): bool
    {
        if (! str_contains($rawInteger, '.')) {
            return $rawInteger !== '' && ctype_digit($rawInteger);
        }

        $groups = explode('.', $rawInteger);
        $first = array_shift($groups);

        if ($first === '' || strlen($first) > 3 || ! ctype_digit($first)) {
            return false;
        }

        foreach ($groups as $group) {
            if (strlen($group) !== 3 || ! ctype_digit($group)) {
                return false;
            }
        }

        return true;
    }
}
