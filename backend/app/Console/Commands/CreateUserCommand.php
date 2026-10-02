<?php

namespace App\Console\Commands;

use App\Domain\Users\Actions\CreateUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

final class CreateUserCommand extends Command
{
    protected $signature = 'user:create {email} {--name= : Nome exibido (padrão: parte local do email)}';

    protected $description = 'Cria um usuário com as categorias padrão';

    public function handle(CreateUser $createUser): int
    {
        $email = (string) $this->argument('email');
        $name = (string) ($this->option('name') ?: strstr($email, '@', true));

        $emailCheck = Validator::make(['email' => $email], ['email' => ['required', 'email', 'unique:users,email']]);
        if ($emailCheck->fails()) {
            $this->error($emailCheck->errors()->first('email'));

            return self::FAILURE;
        }

        $password = (string) $this->secret('Senha');
        if (mb_strlen($password) < 8) {
            $this->error('A senha precisa ter pelo menos 8 caracteres.');

            return self::FAILURE;
        }

        $user = $createUser->handle($name, $email, $password);
        $this->info("Usuário #{$user->id} criado: {$user->email}");

        return self::SUCCESS;
    }
}
