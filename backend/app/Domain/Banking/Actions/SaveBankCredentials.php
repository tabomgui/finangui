<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Errors\BankCredentialsInUse;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Domain\Banking\Providers\Pluggy\PluggyProvider;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
 *
 * Uma linha atual que não descriptografa mais (client_id OU client_secret —
 * ex.: APP_KEY trocada, dado corrompido) é tratada como se não existisse
 * nenhuma credencial: a checagem de troca de conta não se aplica (decidido —
 * não há "conta antiga" legível para comparar, mesmo com conexões), e a
 * gravação sempre apaga a linha atual (legível ou não) e cria uma nova numa
 * transação, em vez de updateOrCreate — assim a recuperação de uma linha
 * corrompida não depende de conseguir atualizá-la.
 */
final class SaveBankCredentials
{
    /**
     * @throws BankCredentialsInUse
     * @throws ValidationException
     */
    public function handle(User $user, #[\SensitiveParameter] string $clientId, #[\SensitiveParameter] string $clientSecret): BankCredential
    {
        // UUID em caixa baixa: a Pluggy aceita os dois jeitos, mas comparar e
        // mostrar o hint sempre na mesma caixa evita duas linhas para a
        // "mesma" conta (uma com client_id maiúsculo, outra minúsculo) e um
        // falso positivo/negativo na checagem de troca de conta abaixo.
        $clientId = mb_strtolower($clientId);

        return DB::transaction(function () use ($user, $clientId, $clientSecret) {
            // Trava a linha do usuário: serializa duas gravações concorrentes
            // desta mesma credencial (ex.: duas abas salvando ao mesmo tempo)
            // para a checagem de troca de conta abaixo e a gravação verem o
            // mesmo estado.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $current = BankCredential::query()
                ->where('user_id', $user->id)
                ->where('provider', BankProviderName::Pluggy)
                ->first();

            $currentClientId = $this->readableClientId($current);

            if ($currentClientId !== null && $currentClientId !== $clientId && $this->hasConnections($user)) {
                throw new BankCredentialsInUse;
            }

            $this->verify($user, $clientId, $clientSecret);

            // client_id mudou (ou a linha antiga era ilegível, então não dá
            // para saber se mudou): a key cacheada da combinação antiga nunca
            // mais vai ser usada por ninguém — limpa de todo modo, por
            // higiene, em vez de deixar expirar sozinha.
            if ($currentClientId !== null && $currentClientId !== $clientId) {
                Cache::forget(PluggyProvider::apiKeyCacheKeyFor($user->id, $currentClientId));
            }

            BankCredential::query()
                ->where('user_id', $user->id)
                ->where('provider', BankProviderName::Pluggy)
                ->delete();

            return BankCredential::query()->create([
                'user_id' => $user->id,
                'provider' => BankProviderName::Pluggy,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'client_id_hint' => mb_substr($clientId, -4),
                'verified_at' => now(),
            ]);
        });
    }

    /**
     * client_id da credencial atual, ou null quando não há nenhuma ou a
     * linha não descriptografa mais (client_id OU client_secret corrompidos
     * — lê os dois para decidir, mesmo só precisando do client_id, porque
     * uma linha com client_secret ilegível também não serve mais para nada).
     */
    private function readableClientId(?BankCredential $current): ?string
    {
        if ($current === null) {
            return null;
        }

        try {
            $clientId = $current->client_id;
            // @phpstan-ignore expr.resultUnused (lê client_secret só para forçar a descriptografia e detectar corrupção também nele; o valor em si não é usado aqui)
            $current->client_secret;
            // @phpstan-ignore catch.neverThrown (falso positivo: Larastan não enxerga que o cast `encrypted` pode lançar ao descriptografar; cobre uma APP_KEY trocada ou dado corrompido, ver tests/Feature/Banking/BankCredentialApiTest.php)
        } catch (DecryptException) {
            return null;
        }

        return $clientId;
    }

    private function hasConnections(User $user): bool
    {
        return BankConnection::query()->where('user_id', $user->id)->exists();
    }

    /**
     * @throws ValidationException quando a Pluggy recusa as credenciais (401/403 em POST /auth)
     */
    private function verify(User $user, #[\SensitiveParameter] string $clientId, #[\SensitiveParameter] string $clientSecret): void
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
