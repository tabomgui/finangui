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
            is_string($value) => $this->fromString($key, $value),
            // @phpstan-ignore function.alreadyNarrowedType (defesa em runtime: o contrato do cast não é garantido pelo PHP)
            is_float($value) => $this->fromFloat($key, $value),
            default => throw new InvalidArgumentException("[{$key}] deve ser Money ou inteiro em centavos."),
        };
    }

    /**
     * Rejeita o que não for uma string de dígitos (com sinal opcional) e o que
     * não couber num inteiro PHP: `filter_var` retorna false em overflow.
     */
    private function fromString(string $key, string $value): int
    {
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw new InvalidArgumentException("[{$key}] deve ser Money ou inteiro em centavos.");
        }

        $filtered = filter_var($value, FILTER_VALIDATE_INT);

        if ($filtered === false) {
            throw new InvalidArgumentException("[{$key}] está fora do intervalo de um inteiro.");
        }

        return $filtered;
    }

    /**
     * Rejeita floats com parte fracionária, não finitos (INF/NAN) ou fora do
     * intervalo de um inteiro PHP. `PHP_INT_MAX` não é representável com
     * precisão em float (arredonda para 2^63), por isso a comparação usa `<`
     * estrito contra `(float) PHP_INT_MAX` em vez de `<=`.
     */
    private function fromFloat(string $key, float $value): int
    {
        if (! is_finite($value) || floor($value) != $value) {
            throw new InvalidArgumentException("[{$key}] deve ser Money ou inteiro em centavos.");
        }

        if ($value < PHP_INT_MIN || $value >= (float) PHP_INT_MAX) {
            throw new InvalidArgumentException("[{$key}] está fora do intervalo de um inteiro.");
        }

        return (int) $value;
    }
}
