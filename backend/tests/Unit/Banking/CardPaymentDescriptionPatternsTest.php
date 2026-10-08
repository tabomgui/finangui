<?php

use App\Domain\Banking\Support\CardPaymentDescriptionPatterns;

it('reconhece os novos padrões de pagamento de fatura, sem se importar com acento e caixa', function (string $description) {
    expect(CardPaymentDescriptionPatterns::matches($description))->toBeTrue();
})->with([
    'Pagamento on line',
    'PAGAMENTO ON LINE',
    'Pagamento online',
    'pagamento ónline',
    'Deb aut parcial',
    'DEB AUT PARCIAL CARTAO',
    'Debito automatico parcial',
    'débito autómatico parcial',
]);

it('não reconhece uma descrição qualquer que não bate com nenhum padrão', function () {
    expect(CardPaymentDescriptionPatterns::matches('Supermercado Exemplo'))->toBeFalse();
});

it('normaliza hífen e ponto para espaço antes de comparar', function (string $description) {
    expect(CardPaymentDescriptionPatterns::matches($description))->toBeTrue();
})->with([
    'Pagto-Debito.Automatico',
    'DEB-AUT.PARCIAL',
    'pagamento.recebido',
    'Pagamento-de-fatura',
]);

it('nunca reconhece uma descrição com "estorno" como pagamento, mesmo contendo um padrão conhecido', function (string $description) {
    expect(CardPaymentDescriptionPatterns::matches($description))->toBeFalse();
})->with([
    'Estorno pagamento recebido',
    'ESTORNO - pagto debito automatico',
    'Pagamento recebido - estornado em seguida por estorno',
]);
