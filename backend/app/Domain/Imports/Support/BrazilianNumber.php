<?php

namespace App\Domain\Imports\Support;

/**
 * Converte um valor monetário de texto para centavos, sem passar por float.
 * Aceita formato brasileiro ("1.234,56", "-50,00") e um formato com ponto
 * decimal puro, sem separador de milhar ("1234.56", 1 ou 2 casas). O formato
 * americano com vírgula de milhar e ponto decimal ("1,234.56") não é
 * suportado.
 */
final class BrazilianNumber
{
    public static function toCents(string $value): ?int
    {
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

            $integerPart = str_replace('.', '', substr($value, 0, $lastComma));
            $decimalPart = substr($value, $lastComma + 1);
        } elseif ($lastComma !== false) {
            $integerPart = substr($value, 0, $lastComma);
            $decimalPart = substr($value, $lastComma + 1);
        } elseif ($lastDot !== false) {
            $decimalPart = substr($value, $lastDot + 1);

            if (substr_count($value, '.') !== 1 || strlen($decimalPart) < 1 || strlen($decimalPart) > 2) {
                return null;
            }

            $integerPart = substr($value, 0, $lastDot);
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

        $cents = (int) ($integerPart.str_pad($decimalPart, 2, '0'));

        return $negative ? -$cents : $cents;
    }
}
