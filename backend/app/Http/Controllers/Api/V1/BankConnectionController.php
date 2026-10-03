<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Actions\CreateConnection;
use App\Domain\Banking\Actions\DisconnectConnection;
use App\Domain\Banking\Actions\LinkAccounts;
use App\Domain\Banking\Actions\MarkReconnected;
use App\Domain\Banking\Actions\QueueConnectionSync;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Models\BankConnection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\ConnectTokenRequest;
use App\Http\Requests\Banking\LinkAccountsRequest;
use App\Http\Requests\Banking\StoreBankConnectionRequest;
use App\Http\Resources\BankConnectionResource;
use App\Http\Resources\ProviderAccountResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * As rotas de bank-connections ficam atrás do middleware
 * App\Http\Middleware\EnsureBankingEnabled (routes/api.php): sem provedor
 * configurado, nenhuma chega a entrar aqui — responde 409 banking_disabled
 * antes até da validação do FormRequest.
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

        return response()->json(['data' => ['connect_token' => $token]]);
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
        /** @var list<array{external_id: string, account_id: int|null}> $links */
        $links = $request->validated('links');

        $connection = $linkAccounts->handle($connection, $links);

        /** @var User $user */
        $user = $request->user();
        $queueConnectionSync->handle($connection, $user->id);

        return BankConnectionResource::make($this->withAccounts($connection));
    }

    public function reconnected(
        Request $request,
        BankConnection $connection,
        MarkReconnected $markReconnected,
        QueueConnectionSync $queueConnectionSync,
    ): BankConnectionResource {
        $connection = $markReconnected->handle($connection);

        /** @var User $user */
        $user = $request->user();
        $queueConnectionSync->handle($connection, $user->id);

        return BankConnectionResource::make($this->withAccounts($connection));
    }

    public function sync(Request $request, BankConnection $connection, QueueConnectionSync $queueConnectionSync): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $queueConnectionSync->handle($connection, $user->id);

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
}
