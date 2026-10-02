<?php

namespace App\Support\Money;

use JsonSerializable;

/**
 * Valor monetário em centavos inteiros. Nunca use float para dinheiro.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(public int $cents) {}

    public static function cents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(Money $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(Money $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function negated(): self
    {
        return new self(-$this->cents);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function equals(Money $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function jsonSerialize(): int
    {
        return $this->cents;
    }
}
