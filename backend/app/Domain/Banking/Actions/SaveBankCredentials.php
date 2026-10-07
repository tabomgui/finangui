<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Enums\SyncTrigger;
use App\Domain\Banking\Errors\BankCredentialsInUse;
use App\Domain\Banking\Errors\ConnectionSyncInProgress;
use App\Domain\Banking\Errors\ProviderAuthFailed;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Models\BankCredential;
use App\Domain\Banking\Providers\Pluggy\PluggyProvider;
use App\Models\User;
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
 * Trocar de client_id (conta Pluggy diferente) é bloqueado (BankCredentialsInUse)
 * só quando o usuário tem conexões bancárias cujo
 * `credential_fingerprint` bate com o da conta atual (ver
 * App\Domain\Banking\Models\BankCredential::fingerprint()/currentFingerprintFor()
 * e App\Domain\Banking\Models\BankConnection) — essas pertencem de fato à
 * conta antiga, então o usuário precisa desconectá-las primeiro. Conexão com
 * fingerprint null (criada sob a conta global antiga, por variável de
 * ambiente, antes de existir essa coluna, ou ainda não adotada por nenhum
 * sync bem-sucedido — ver App\Domain\Banking\Jobs\SyncConnection) ou de uma
 * terceira conta nunca bloqueia. Trocar só o client_secret do mesmo
 * client_id é sempre permitido. Um usuário sem credencial cadastrada
 * também pode salvar — não há "conta antiga" para comparar.
 *
 * Uma linha atual que não descriptografa mais (client_id OU client_secret —
 * ex.: APP_KEY trocada, dado corrompido) é tratada como se não existisse
 * nenhuma credencial: a checagem de troca de conta não se aplica (decidido —
 * não há "conta antiga" legível para comparar, mesmo com conexões), e a
 * gravação sempre apaga a linha atual (legível ou não) e cria uma nova numa
 * transação, em vez de updateOrCreate — assim a recuperação de uma linha
 * corrompida não depende de conseguir atualizá-la.
 *
 * Depois que a transação comita, toda conexão do usuário em `error` é
 * reenviada para sincronizar (QueueConnectionSync, ignorando
 * ConnectionSyncInProgress — mesmo padrão de
 * App\Http\Controllers\Api\V1\BankConnectionController::queueSyncIgnoringInProgress):
 * cobre tanto a credencial recém-cadastrada por um usuário que só tinha
 * conexões legadas (fingerprint null, mensagem "Cadastre suas credenciais…")
 * quanto a troca de secret de uma credencial que a Pluggy vinha recusando.
 */
final class SaveBankCredentials
{
    public function __construct(private readonly QueueConnectionSync $queueConnectionSync) {}

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

        // Checagem barata antes de falar com a Pluggy: troca bloqueada falha
        // sem nenhuma chamada de rede.
        $this->currentClientIdAllowingSwitch($user, $clientId);

        // O teste na Pluggy (até ~25s) fica fora da transação para não segurar
        // a trava da linha do usuário durante a chamada de rede.
        $this->verify($user, $clientId, $clientSecret);

        $credential = DB::transaction(function () use ($user, $clientId, $clientSecret) {
            // Trava a linha do usuário e refaz a checagem: serializa duas
            // gravações concorrentes (ex.: duas abas) e pega uma conexão
            // criada enquanto a Pluggy respondia.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $currentClientId = $this->currentClientIdAllowingSwitch($user, $clientId);

            // client_id mudou: a key cacheada da combinação antiga nunca mais
            // vai ser usada — limpa por higiene em vez de deixar expirar.
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

        $this->queueErrorConnectionSyncs($user);

        return $credential;
    }

    /**
     * A credencial nova pode resolver o motivo de uma conexão ter parado em
     * `error` (sem isso, a mensagem "Cadastre suas credenciais…" só sairia
     * da tela no próximo sync agendado, até 6h depois). Um sync que falhar
     * de novo (ex.: secret ainda errado) volta a gravar `error` do jeito
     * normal — ver App\Domain\Banking\Jobs\SyncConnection.
     */
    private function queueErrorConnectionSyncs(User $user): void
    {
        $connections = BankConnection::query()
            ->where('user_id', $user->id)
            ->where('status', ConnectionStatus::Error)
            ->get();

        foreach ($connections as $connection) {
            try {
                $this->queueConnectionSync->handle($connection, SyncTrigger::Credentials);
            } catch (ConnectionSyncInProgress) {
                // Nada a fazer: um sync já está enfileirado ou rodando.
            }
        }
    }

    /**
     * client_id legível da credencial atual (null sem credencial ou com linha
     * ilegível); lança BankCredentialsInUse quando o novo client_id é de outra
     * conta e o usuário tem conexões com o fingerprint da conta atual.
     *
     * @throws BankCredentialsInUse
     */
    private function currentClientIdAllowingSwitch(User $user, string $clientId): ?string
    {
        $current = BankCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->first();

        $currentClientId = BankCredential::readableClientId($current);

        if ($currentClientId !== null && $currentClientId !== $clientId && $this->hasConnectionsFor($user, $currentClientId)) {
            throw new BankCredentialsInUse;
        }

        return $currentClientId;
    }

    /**
     * Só conta como "em uso" a conexão cujo credential_fingerprint bate com
     * o fingerprint de $clientId (a conta atual) — null ou de outra conta
     * nunca bloqueia (ver App\Domain\Banking\Models\BankConnection).
     */
    private function hasConnectionsFor(User $user, string $clientId): bool
    {
        return BankConnection::query()
            ->where('user_id', $user->id)
            ->where('credential_fingerprint', BankCredential::fingerprint($clientId))
            ->exists();
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
