<?php

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Model;

beforeEach(function () {
    $this->cast = new MoneyCast;
    $this->model = new class extends Model {};
});

it('converte o valor do banco em Money', function () {
    expect($this->cast->get($this->model, 'amount', '1500', [])->cents)->toBe(1500)
        ->and($this->cast->get($this->model, 'amount', null, []))->toBeNull();
});

it('grava Money e inteiros como centavos', function () {
    expect($this->cast->set($this->model, 'amount', Money::cents(99), []))->toBe(99)
        ->and($this->cast->set($this->model, 'amount', 120, []))->toBe(120)
        ->and($this->cast->set($this->model, 'amount', null, []))->toBeNull();
});

it('aceita string numérica inteira', function () {
    expect($this->cast->set($this->model, 'amount', '4590', []))->toBe(4590)
        ->and($this->cast->set($this->model, 'amount', '-120', []))->toBe(-120)
        ->and($this->cast->set($this->model, 'amount', '0', []))->toBe(0);
});

it('aceita float sem parte fracionária', function () {
    expect($this->cast->set($this->model, 'amount', 2000.0, []))->toBe(2000)
        ->and($this->cast->set($this->model, 'amount', -15.0, []))->toBe(-15);
});

it('recusa float com parte fracionária', function () {
    expect(fn () => $this->cast->set($this->model, 'amount', 10.5, []))
        ->toThrow(InvalidArgumentException::class);
});

it('recusa string não inteira', function () {
    expect(fn () => $this->cast->set($this->model, 'amount', '10.5', []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->cast->set($this->model, 'amount', 'abc', []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->cast->set($this->model, 'amount', '', []))
        ->toThrow(InvalidArgumentException::class);
});

it('recusa string numérica fora do range de inteiro', function () {
    expect(fn () => $this->cast->set($this->model, 'amount', '99999999999999999999999', []))
        ->toThrow(InvalidArgumentException::class);
});

it('recusa float fora do range de inteiro', function () {
    expect(fn () => $this->cast->set($this->model, 'amount', 1e20, []))
        ->toThrow(InvalidArgumentException::class);
});

it('recusa infinito, NaN e tipos não escalares', function () {
    expect(fn () => $this->cast->set($this->model, 'amount', INF, []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->cast->set($this->model, 'amount', NAN, []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->cast->set($this->model, 'amount', [120], []))
        ->toThrow(InvalidArgumentException::class);
});
