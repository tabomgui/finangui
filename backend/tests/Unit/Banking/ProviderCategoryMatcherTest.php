<?php

use App\Domain\Banking\Support\ProviderCategoryMatcher;
use App\Domain\Transactions\Enums\Direction;

function usableCategory(int $id, string $kind = 'expense', bool $isTransfer = false, bool $hasParent = false): array
{
    return ['id' => $id, 'kind' => $kind, 'is_transfer' => $isTransfer, 'has_parent' => $hasParent];
}

it('casa por sinônimo da folha', function () {
    $byName = ['MERCADO' => [usableCategory(1)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Supermercado', 'parent' => null], $byName, Direction::Out);

    expect($id)->toBe(1);
});

it('casa por sinônimo do pai quando a folha não tem sinônimo nem bate direto', function () {
    $byName = ['COMBUSTIVEL' => [usableCategory(2)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Postos', 'parent' => 'Postos de gasolina'], $byName, Direction::Out);

    expect($id)->toBe(2);
});

it('casa pelo nome exato da folha quando não há sinônimo', function () {
    $byName = ['VIAGENS' => [usableCategory(3)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Viagens', 'parent' => 'Travel'], $byName, Direction::Out);

    expect($id)->toBe(3);
});

it('casa pelo nome exato do pai quando a folha não bate', function () {
    $byName = ['ALIMENTACAO E BEBIDAS' => [usableCategory(4)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Um subtipo qualquer', 'parent' => 'Alimentação e bebidas'], $byName, Direction::Out);

    expect($id)->toBe(4);
});

it('respeita kind vs direction: categoria de kind errado não casa', function () {
    $byName = ['MERCADO' => [usableCategory(1, kind: 'income')]];

    $id = ProviderCategoryMatcher::match(['name' => 'Supermercado', 'parent' => null], $byName, Direction::Out);

    expect($id)->toBeNull();
});

it('nunca escolhe categoria is_transfer por nome exato, só por sinônimo explícito', function () {
    // "Transferências" é is_transfer; o nome exato "Transferências" (sem
    // vir de um sinônimo de transferência) não deveria escolhê-la.
    $byName = ['TRANSFERENCIAS' => [usableCategory(5, isTransfer: true)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Transferências', 'parent' => null], $byName, Direction::Out);

    expect($id)->toBeNull();
});

it('sinônimo de pagamento de cartão escolhe categoria is_transfer', function () {
    $byName = ['PAGAMENTO DE FATURA' => [usableCategory(6, isTransfer: true)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Pagamento de cartão de crédito', 'parent' => 'Transferências'], $byName, Direction::Out);

    expect($id)->toBe(6);
});

it('sinônimo de mesma titularidade escolhe categoria is_transfer', function () {
    $byName = ['TRANSFERENCIAS' => [usableCategory(7, isTransfer: true)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Transferência mesma titularidade', 'parent' => null], $byName, Direction::Out);

    expect($id)->toBe(7);
});

it('sinônimo de investimentos escolhe categoria is_transfer', function () {
    $byName = ['INVESTIMENTOS' => [usableCategory(8, isTransfer: true)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Investimentos', 'parent' => null], $byName, Direction::Out);

    expect($id)->toBe(8);
});

it('colisão: prefere a subcategoria quando há uma raiz e uma filha com o mesmo nome', function () {
    $byName = ['MERCADO' => [usableCategory(1, hasParent: false), usableCategory(2, hasParent: true)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Supermercado', 'parent' => null], $byName, Direction::Out);

    expect($id)->toBe(2);
});

it('colisão ambígua (duas subcategorias com o mesmo nome) não escolhe nenhuma', function () {
    $byName = ['MERCADO' => [usableCategory(1, hasParent: true), usableCategory(2, hasParent: true)]];

    $id = ProviderCategoryMatcher::match(['name' => 'Supermercado', 'parent' => null], $byName, Direction::Out);

    expect($id)->toBeNull();
});

it('sem nenhuma categoria usável com o nome (sinônimo ou exato), devolve null', function () {
    $id = ProviderCategoryMatcher::match(['name' => 'Categoria Inexistente', 'parent' => null], [], Direction::Out);

    expect($id)->toBeNull();
});

it('direção de entrada usa kind income', function () {
    $byName = ['SALARIO' => [usableCategory(9, kind: 'income')]];

    $id = ProviderCategoryMatcher::match(['name' => 'Salário', 'parent' => null], $byName, Direction::In);

    expect($id)->toBe(9);
});
