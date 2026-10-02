<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }
}
