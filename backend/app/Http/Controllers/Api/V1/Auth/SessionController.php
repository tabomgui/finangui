<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class SessionController extends Controller
{
    public function store(LoginRequest $request): UserResource|JsonResponse
    {
        if (! $request->hasSession()) {
            return response()->json([
                'code' => 'session_required',
                'message' => 'Login só é aceito a partir do frontend (origem stateful).',
            ], 400);
        }

        if (! Auth::attempt($request->only('email', 'password'), remember: true)) {
            throw ValidationException::withMessages(['email' => 'Email ou senha inválidos.']);
        }

        $request->session()->regenerate();

        return UserResource::make(Auth::user());
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
