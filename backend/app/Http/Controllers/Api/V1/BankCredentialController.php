<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Banking\Actions\DeleteBankCredentials;
use App\Domain\Banking\Actions\SaveBankCredentials;
use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Models\BankCredential;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\SaveBankCredentialsRequest;
use App\Http\Resources\BankCredentialsResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Credenciais da Pluggy cadastradas pelo próprio usuário (Configurações):
 * ver App\Domain\Banking\Models\BankCredential. Fora do middleware
 * App\Http\Middleware\EnsureBankingEnabled de propósito — é por aqui que a
 * integração é "ligada" pela primeira vez.
 */
final class BankCredentialController extends Controller
{
    public function show(Request $request): BankCredentialsResource
    {
        /** @var User $user */
        $user = $request->user();

        $credential = BankCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->first();

        return BankCredentialsResource::make($credential);
    }

    public function save(SaveBankCredentialsRequest $request, SaveBankCredentials $saveBankCredentials): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var string $clientId */
        $clientId = $request->validated('client_id');
        /** @var string $clientSecret */
        $clientSecret = $request->validated('client_secret');

        $credential = $saveBankCredentials->handle($user, $clientId, $clientSecret);

        // Upsert: sempre 200, mesmo na primeira vez (quando o model acabou
        // de ser criado e o wasRecentlyCreated do Eloquent faria o
        // JsonResource devolver 201 por padrão) — ver App\Http\Controllers\Api\V1\Auth\RegisterController
        // para o mesmo ajuste no cadastro.
        $credential->wasRecentlyCreated = false;

        return BankCredentialsResource::make($credential)->response()->setStatusCode(200);
    }

    public function destroy(Request $request, DeleteBankCredentials $deleteBankCredentials): Response
    {
        /** @var User $user */
        $user = $request->user();

        $deleteBankCredentials->handle($user);

        return response()->noContent();
    }
}
