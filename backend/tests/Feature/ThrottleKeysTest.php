<?php

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Providers\FakeBankProvider;

/**
 * O middleware "throttle:max,min" genérico (sem limiter nomeado) usa só o
 * id do usuário autenticado como chave — sem um prefixo próprio por rota,
 * toda rota autenticada que usasse esse middleware sem prefixo
 * compartilharia o mesmo contador: martelar uma rota de limite alto
 * (rules/preview, 60/min) consumiria o limite de uma rota de limite baixo
 * (bank-connections/.../sync, 6/min) bem antes do seu próprio teto.
 */
it('martelar rules/preview não consome o limite de bank-connections/.../sync', function () {
    app()->instance(BankProvider::class, new FakeBankProvider);
    actingAsUser();
    $connection = BankConnection::factory()->active()->create();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/rules/preview', []);
    }

    $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")->assertStatus(202);
});
