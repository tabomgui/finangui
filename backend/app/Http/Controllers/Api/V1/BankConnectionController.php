<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Actions\CreateConnection;
use App\Domain\Banking\Actions\DisconnectConnection;
use App\Domain\Banking\Actions\LinkAccounts;
use App\Domain\Banking\Actions\MarkReconnected;
use App\Domain\Banking\Actions\QueueConnectionSync;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Errors\ConnectionItemMismatch;
use App\Domain\Banking\Errors\ConnectionSyncInProgress;
use App\Domain\Banking\Models\BankConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\ConnectTokenRequest;
use App\Http\Requests\Banking\LinkAccountsRequest;
use App\Http\Requests\Banking\ReconnectedRequest;
use App\Http\Requests\Banking\StoreBankConnectionRequest;
use App\Http\Resources\BankConnectionResource;
use App\Http\Resources\ProviderAccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * As rotas que falam com o provedor (connect-token, criar, vincular,
 * reconectar, sincronizar) ficam atrás do middleware
 * App\Http\Middleware\EnsureBankingEnabled (routes/api.php): sem provedor
 * configurado, nenhuma chega a entrar aqui — responde 409 banking_disabled
 * antes até da validação do FormRequest. `index` e `destroy` ficam fora
 * desse grupo de propósito: listar conexões já existentes e desconectar
 * (que só limpa o lado local — ver DisconnectConnection) continuam
 * funcionando mesmo depois das credenciais serem removidas.
 */
final class BankConnectionController extends Controller
{
    public function __construct(private readonly BankProvider $provider) {}

    public function index(): AnonymousResourceCollection
    {
        $connections = BankConnection::query()
            ->with(['accounts' => fn ($query) => $query->withBalance()])
            ->orderByDesc('id')
            ->get();

        return BankConnectionResource::collection($connections);
    }

    public function connectToken(ConnectTokenRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $itemId = null;

        if ($request->filled('connection_id')) {
            // findOrFail (não Rule::exists no request): uma connection_id de
            // outro usuário precisa responder 404, não 422 — o global scope
            // de BelongsToUser já faz a conexão "não existir" pra quem não é
            // o dono.
            $connection = BankConnection::query()->findOrFail($request->integer('connection_id'));
            $itemId = $connection->external_id;
        }

        $token = $this->provider->connectToken(CreateConnection::clientUserId($user), $itemId);

        return response()->json(['data' => $this->connectTokenData($token, $itemId)]);
    }

    /**
     * `item_id` só aparece quando o pedido veio com `connection_id` (modo atualização): o
     * frontend usa esse valor para abrir o widget com `updateItem` e para conferir, no
     * sucesso, que o item devolvido é o mesmo que foi pedido. Omitido (nunca `null`) nos
     * outros casos — mesmo motivo de TransactionResource::categorization() (uma propriedade
     * tipada só como `null` desaparece do lado do cliente).
     *
     * @return array{connect_token: string}|array{connect_token: string, item_id: string}
     */
    private function connectTokenData(string $token, ?string $itemId): array
    {
        return $itemId !== null ? ['connect_token' => $token, 'item_id' => $itemId] : ['connect_token' => $token];
    }

    public function store(StoreBankConnectionRequest $request, CreateConnection $createConnection): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var string $itemId */
        $itemId = $request->validated('item_id');

        $result = $createConnection->handle($user, $itemId);

        return response()->json([
            'data' => [
                'connection' => BankConnectionResource::make($this->withAccounts($result['connection'])),
                'provider_accounts' => ProviderAccountResource::collection($result['providerAccounts']),
            ],
        ], 201);
    }

    public function linkAccounts(
        LinkAccountsRequest $request,
        BankConnection $connection,
        LinkAccounts $linkAccounts,
        QueueConnectionSync $queueConnectionSync,
    ): BankConnectionResource {
        /** @var list<array{external_id: string, account_id?: int|null}> $links */
        $links = $request->validated('links');

        $connection = $linkAccounts->handle($connection, $links);
        $this->queueSyncIgnoringInProgress($queueConnectionSync, $connection);

        return BankConnectionResource::make($this->withAccounts($connection));
    }

    public function reconnected(
        ReconnectedRequest $request,
        BankConnection $connection,
        MarkReconnected $markReconnected,
        QueueConnectionSync $queueConnectionSync,
    ): BankConnectionResource {
        // O widget em modo de atualização sempre devolve o mesmo item (updateItem no connect
        // token); um item diferente aqui é um pedido adulterado ou um fluxo que ficou aberto
        // demais e foi reaproveitado para outra conexão — não confiamos sem conferir.
        if ($request->validated('item_id') !== $connection->external_id) {
            throw new ConnectionItemMismatch;
        }

        $connection = $markReconnected->handle($connection);
        $this->queueSyncIgnoringInProgress($queueConnectionSync, $connection);

        return BankConnectionResource::make($this->withAccounts($connection));
    }

    public function sync(BankConnection $connection, QueueConnectionSync $queueConnectionSync): JsonResponse
    {
        $queueConnectionSync->handle($connection);

        return response()->json(['data' => ['queued' => true]], 202);
    }

    public function destroy(BankConnection $connection, DisconnectConnection $disconnectConnection): Response
    {
        $disconnectConnection->handle($connection);

        return response()->noContent();
    }

    private function withAccounts(BankConnection $connection): BankConnection
    {
        return $connection->load(['accounts' => fn ($query) => $query->withBalance()]);
    }

    /**
     * Vincular e reconectar sempre tentam puxar um sync na hora, mas não é
     * um erro pra quem chamou se já tiver um a caminho (ex.: o agendador
     * pegou esta conexão entre o vínculo/reconexão e esta chamada) — só o
     * botão "Sincronizar" explícito (`sync()`) precisa do 409.
     */
    private function queueSyncIgnoringInProgress(QueueConnectionSync $queueConnectionSync, BankConnection $connection): void
    {
        try {
            $queueConnectionSync->handle($connection);
        } catch (ConnectionSyncInProgress) {
            // Nada a fazer: um sync já está enfileirado ou rodando.
        }
    }
}
