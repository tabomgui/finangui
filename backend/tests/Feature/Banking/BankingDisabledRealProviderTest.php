<?php

use App\Domain\Banking\Models\BankConnection;

/**
 * BankConnectionApiTest cobre banking_disabled com a fábrica fake
 * (FakeBankProvider::setEnabled(false), ver tests/Pest.php); este arquivo
 * prova a mesma regra com a fábrica real (App\Domain\Banking\Providers\Pluggy\PluggyProviderFactory),
 * sem nenhum App\Domain\Banking\Models\BankCredential cadastrado para o usuário.
 */
it('sem credenciais da Pluggy cadastradas, todas as rotas que falam com o provedor respondem 409 banking_disabled', function () {
    $user = actingAsUser();
    $connection = BankConnection::factory()->create(['user_id' => $user->id]);

    $this->postJson('/api/v1/bank-connections/connect-token')
        ->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
    $this->postJson('/api/v1/bank-connections', ['item_id' => '00000000-0000-0000-0000-0000000000a1'])
        ->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
    $this->postJson("/api/v1/bank-connections/{$connection->id}/link-accounts", ['links' => []])
        ->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
    $this->postJson("/api/v1/bank-connections/{$connection->id}/reconnected")
        ->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
    $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")
        ->assertStatus(409)->assertJsonPath('code', 'banking_disabled');
});

it('index e destroy continuam funcionando sem credenciais cadastradas (fábrica real)', function () {
    $user = actingAsUser();
    $connection = BankConnection::factory()->active()->create(['user_id' => $user->id, 'external_id' => 'item-x']);

    $this->getJson('/api/v1/bank-connections')->assertOk();
    $this->deleteJson("/api/v1/bank-connections/{$connection->id}")->assertNoContent();

    expect(BankConnection::find($connection->id))->toBeNull();
});
