<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Users\Actions\UpdateProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

final class MeController extends Controller
{
    public function show(Request $request): UserResource
    {
        return UserResource::make($request->user());
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $updateProfile): UserResource
    {
        /** @var User $user */
        $user = $request->user();

        $cleared = $updateProfile->handle($user, $request->validated());

        return UserResource::make($user)->additional([
            'meta' => [
                // Quantos lançamentos perderam a categoria do banco nesta chamada (clear_provider_categories); 0 quando não houve limpeza.
                'cleared_provider_categories' => $cleared,
            ],
        ]);
    }
}
