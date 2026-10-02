<?php

namespace Database\Seeders;

use App\Domain\Users\Actions\CreateUser;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Usuário de desenvolvimento: dev@finangui.test / password
     */
    public function run(CreateUser $createUser): void
    {
        $createUser->handle('Dev', 'dev@finangui.test', 'password');
    }
}
