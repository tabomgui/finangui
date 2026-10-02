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
        bool $emailVerified = false,
    ): User {
        return DB::transaction(function () use ($name, $email, $password, $googleId, $avatar, $emailVerified) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'google_id' => $googleId,
                'avatar' => $avatar,
            ]);

            // email_verified_at não é fillable (só quem confirmou a posse do email
            // por um canal confiável, aqui o operador via CLI ou o próprio Google, pode setá-lo).
            if ($emailVerified) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->seedDefaultCategories->handle($user);

            return $user;
        });
    }
}
