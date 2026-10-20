<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Categorization\Actions\UpdateProviderCategoriesSetting;
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

    public function update(UpdateProfileRequest $request, UpdateProviderCategoriesSetting $updateProviderCategories): UserResource
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }
        if (array_key_exists('password', $data)) {
            $user->password = $data['password'];
        }
        $user->save();

        $cleared = 0;

        if (array_key_exists('use_provider_categories', $data)) {
            $cleared = $updateProviderCategories->handle(
                $user,
                (bool) $data['use_provider_categories'],
                (bool) ($data['clear_provider_categories'] ?? false),
            );
        }

        return UserResource::make($user)->additional([
            'meta' => [
                // Quantos lançamentos perderam a categoria do banco nesta chamada (clear_provider_categories); 0 quando não houve limpeza.
                'cleared_provider_categories' => $cleared,
            ],
        ]);
    }
}
