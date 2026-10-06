<?php

use App\Domain\Banking\Models\BankConnection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * O middleware "throttle:max,min" genérico (sem limiter nomeado) usa só o
 * id do usuário autenticado como chave — sem um prefixo próprio por rota,
 * toda rota autenticada que usasse esse middleware sem prefixo
 * compartilharia o mesmo contador: martelar uma rota de limite alto
 * (rules/preview, 60/min) consumiria o limite de uma rota de limite baixo
 * (bank-connections/.../sync, 6/min) bem antes do seu próprio teto.
 */
it('martelar rules/preview não consome o limite de bank-connections/.../sync', function () {
    fakeBankProvider();
    $user = actingAsUser();
    verifiedBankCredential($user);
    $connection = BankConnection::factory()->active()->create();

    // Queue::fake(): só o throttle da rota importa aqui, não o resultado
    // do sync em si — sem isso, QUEUE_CONNECTION=sync (phpunit.xml) rodaria
    // SyncConnection na hora, e ele falharia por não achar um item fake
    // para o external_id desta conexão (o que esta conexão sincroniza não
    // é o que este teste quer exercitar).
    Queue::fake();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/rules/preview', []);
    }

    $this->postJson("/api/v1/bank-connections/{$connection->id}/sync")->assertStatus(202);
});

it('bank-connections (store) e link-accounts têm limites próprios, isolados de rules/preview', function () {
    fakeBankProvider();
    $user = actingAsUser();
    verifiedBankCredential($user);

    // store: 10/min — a 11ª bate no limite (429); nenhuma delas deveria
    // ter sucesso de verdade (item_id aleatório, sem contas), então o que
    // importa aqui é só o código de status não ser 429 antes da 11ª.
    for ($i = 0; $i < 10; $i++) {
        expect($this->postJson('/api/v1/bank-connections', ['item_id' => (string) Str::uuid()])->status())->not->toBe(429);
    }
    $this->postJson('/api/v1/bank-connections', ['item_id' => (string) Str::uuid()])->assertStatus(429);

    // Nem rules/preview (prefixo diferente) nem link-accounts (prefixo
    // próprio, 20/min) foram afetados por martelar store.
    expect($this->postJson('/api/v1/rules/preview', [])->status())->not->toBe(429);

    $connection = BankConnection::factory()->create();
    for ($i = 0; $i < 20; $i++) {
        expect($this->postJson("/api/v1/bank-connections/{$connection->id}/link-accounts", ['links' => []])->status())->not->toBe(429);
    }
    $this->postJson("/api/v1/bank-connections/{$connection->id}/link-accounts", ['links' => []])->assertStatus(429);
});

it('transfer-suggestions/detect tem limite próprio (6/min): martelar rules/preview não o afeta, e martelar detect não afeta rules/preview', function () {
    actingAsUser();

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/rules/preview', []);
    }

    // Ainda não bateu no próprio limite (6/min): martelar rules/preview
    // (prefixo diferente) não consumiu nada dele.
    for ($i = 0; $i < 6; $i++) {
        $this->postJson('/api/v1/transfer-suggestions/detect')->assertStatus(200);
    }

    // A 7ª bate no limite próprio.
    $this->postJson('/api/v1/transfer-suggestions/detect')->assertStatus(429);

    // rules/preview continua livre: martelar detect não consumiu o limite dele.
    expect($this->postJson('/api/v1/rules/preview', [])->status())->not->toBe(429);
});
