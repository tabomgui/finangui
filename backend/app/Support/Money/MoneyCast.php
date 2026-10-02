<?php

namespace App\Support\Money;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * @implements CastsAttributes<Money|null, Money|int|float|string|null>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::cents((int) $value);
    }

    /**
     * Aceita Money, inteiro, string numérica inteira (ex.: "4590") ou float sem
     * parte fracionária (ex.: 2000.0): a regra de validação `integer` aceita
     * ambos os formatos, então o cast precisa normalizá-los antes de gravar.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Money => $value->cents,
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            is_float($value) && is_finite($value) && floor($value) == $value => (int) $value,
            default => throw new InvalidArgumentException("[{$key}] deve ser Money ou inteiro em centavos."),
        };
    }
}
