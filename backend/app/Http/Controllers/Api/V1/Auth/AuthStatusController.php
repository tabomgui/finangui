<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Support\GoogleCredentials;
use Illuminate\Http\JsonResponse;

final class AuthStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'google_login_enabled' => GoogleCredentials::configured(),
            'registration_enabled' => (bool) config('finangui.registration_enabled'),
        ]]);
    }
}
