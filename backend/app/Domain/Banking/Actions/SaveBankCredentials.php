<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Errors\BankCredentialsInUse;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Domain\Banking\Providers\Pluggy\PluggyProvider;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Upsert da credencial da Pluggy do usuário (uma por usuário/provedor):
 * testa client_id/client_secret contra a Pluggy de verdade (POST /auth, via
 * PluggyProvider::verifyCredentials()) antes de gravar — credenciais
 * recusadas nunca chegam a tocar o banco. ProviderUnavailable (indisponi-
 * bilidade transitória) não é capturado aqui: sobe até o renderer global
 * (bootstrap/app.php), que já responde 503 provider_unavailable.
 *
 * Trocar de client_id (conta Pluggy diferente) com conexões bancárias
 * existentes (qualquer status) é bloqueado (BankCredentialsInUse): os itens
 * cadastrados pertencem à conta antiga, então o usuário precisa desconectar
 * os bancos primeiro. Trocar só o client_secret do mesmo client_id é sempre
 * permitido. Um usuário com conexões mas sem credencial cadastrada (conexões
 * de quando a Pluggy era configurada por variável de ambiente, antes desta
 * tabela existir) também pode salvar — não há "conta antiga" para comparar.
 */
final class SaveBankCredentials
{
    public function handle(User $user, string $clientId, string $clientSecret): BankCredential
    {
        $current = BankCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->first();

        if ($current !== null && $current->client_id !== $clientId && $this->hasConnections($user)) {
            throw new BankCredentialsInUse;
        }

        $this->verify($user, $clientId, $clientSecret);

        return BankCredential::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => BankProviderName::Pluggy],
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'client_id_hint' => mb_substr($clientId, -4),
                'verified_at' => now(),
            ],
        );
    }

    private function hasConnections(User $user): bool
    {
        return BankConnection::query()->where('user_id', $user->id)->exists();
    }

    private function verify(User $user, string $clientId, string $clientSecret): void
    {
        // Limpa a key cacheada desta combinação (usuário, client_id) antes
        // de testar: se o client_id não mudou (só o secret), uma key ainda
        // válida do secret antigo cacheada sob a mesma chave faria o teste
        // passar sem de fato chamar a Pluggy com o secret novo.
        Cache::forget(PluggyProvider::apiKeyCacheKeyFor($user->id, $clientId));

        /** @var string $baseUrl */
        $baseUrl = config('services.pluggy.base_url');
        $provider = new PluggyProvider($clientId, $clientSecret, $baseUrl, $user->id);

        try {
            $provider->verifyCredentials();
        } catch (ProviderAuthFailed) {
            throw ValidationException::withMessages([
                'client_secret' => 'A Pluggy recusou essas credenciais.',
            ]);
        }
    }
}
