<?php

use App\Domain\Banking\Data\ProviderItem;

function providerItemDto(array $overrides = []): ProviderItem
{
    return new ProviderItem(
        id: $overrides['id'] ?? 'item-1',
        status: $overrides['status'] ?? 'UPDATED',
        clientUserId: null,
        lastUpdatedAt: null,
        institutionName: $overrides['institutionName'] ?? 'Banco Exemplo',
        institutionLogoUrl: null,
        errorMessage: null,
    );
}

it('refreshUnsupported() reconhece "MeuPluggy" sem se importar com caixa', function (string $name) {
    expect(providerItemDto(['institutionName' => $name])->refreshUnsupported())->toBeTrue();
})->with(['MeuPluggy', 'meupluggy', 'MEUPLUGGY']);

it('refreshUnsupported() é false para um conector comum, mesmo com nome parecido', function (string $name) {
    expect(providerItemDto(['institutionName' => $name])->refreshUnsupported())->toBeFalse();
})->with(['Outro Banco', 'MeuPluggy Bank', 'Pluggy']);

it('refreshUnsupported() é false sem nome de conector nenhum', function () {
    expect(providerItemDto(['institutionName' => null])->refreshUnsupported())->toBeFalse();
});
