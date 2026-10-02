<?php

namespace App\Domain\Users\Actions;

use App\Domain\Categories\Actions\SeedDefaultCategories;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    public function __construct(private readonly SeedDefaultCategories $seedDefaultCategories) {}

    public function handle(
        string $name,
        string $email,
        ?string $password = null,
        ?string $googleId = null,
        ?string $avatar = null,
    ): User {
        return DB::transaction(function () use ($name, $email, $password, $googleId, $avatar) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'google_id' => $googleId,
                'avatar' => $avatar,
            ]);

            $this->seedDefaultCategories->handle($user);

            return $user;
        });
    }
}
