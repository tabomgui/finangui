<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Users\Actions\CreateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, CreateUser $createUser): JsonResponse
    {
        if (! config('finangui.registration_enabled')) {
            return response()->json([
                'code' => 'registration_closed',
                'message' => 'O cadastro está fechado nesta instância.',
            ], 403);
        }

        if (! $request->hasSession()) {
            return response()->json([
                'code' => 'session_required',
                'message' => 'Cadastro só é aceito a partir do frontend (origem stateful).',
            ], 400);
        }

        $user = $createUser->handle(
            $request->string('name')->value(),
            $request->string('email')->value(),
            $request->string('password')->value(),
        );

        // A resposta do cadastro já força 201 explicitamente abaixo; zera a flag
        // para que o guard não devolva esse mesmo 201 em requisições futuras que
        // reutilizem a instância cacheada (ex.: GET /me logo depois).
        $user->wasRecentlyCreated = false;

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return UserResource::make($user)->response()->setStatusCode(201);
    }
}
