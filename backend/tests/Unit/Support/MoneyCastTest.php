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

it('recusa float', function () {
    expect(fn () => $this->cast->set($this->model, 'amount', 10.5, []))
        ->toThrow(InvalidArgumentException::class);
});
