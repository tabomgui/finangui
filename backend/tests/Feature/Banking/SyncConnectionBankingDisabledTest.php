<?php

use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\BankingDisabled;
use App\Domain\Banking\Jobs\SyncConnection;
use App\Domain\Banking\Models\BankConnection;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Conexões criadas antes desta mudança (credenciais globais por variável de
 * ambiente) ficam sem nenhum App\Domain\Banking\Models\BankCredential logo
 * depois do deploy, até o usuário cadastrar as próprias credenciais em
 * Configurações — a fábrica real (PluggyProviderFactory) lança
 * BankingDisabled e App\Domain\Banking\Jobs\SyncConnection precisa terminar
 * "com sucesso" para a fila (sem retry), com o erro gravado na conexão.
 */
it('sem nenhuma credencial cadastrada para o dono, termina em error com a mensagem de BankingDisabled, sem falar com a Pluggy e sem relançar', function () {
    $owner = User::factory()->create();
    $connection = BankConnection::factory()->active()->create(['user_id' => $owner->id, 'external_id' => 'item-x']);

    Http::fake();

    app()->call([new SyncConnection($connection->id), 'handle']);

    expect($connection->refresh()->status)->toBe(ConnectionStatus::Error)
        ->and($connection->last_error)->toBe((new BankingDisabled)->getMessage());

    Http::assertNothingSent();
});
