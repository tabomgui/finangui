<?php

namespace App\Domain\Users\Actions;

use App\Domain\Categorization\Actions\UpdateProviderCategoriesSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /me: nome, senha e a configuração "Usar categorias do banco" (com a
 * limpeza opcional, ver UpdateProviderCategoriesSetting) numa única transação
 * — uma falha na limpeza não deixa o nome ou a senha trocados pela metade.
 */
final class UpdateProfile
{
    public function __construct(
        private readonly UpdateProviderCategoriesSetting $updateProviderCategories,
    ) {}

    /**
     * @param  array<string, mixed>  $data  campos já validados (UpdateProfileRequest); só os presentes mudam
     * @return int quantos lançamentos perderam a categoria do banco (0 quando não houve limpeza)
     */
    public function handle(User $user, array $data): int
    {
        return DB::transaction(function () use ($user, $data): int {
            if (array_key_exists('name', $data)) {
                $user->name = (string) $data['name'];
            }
            if (array_key_exists('password', $data)) {
                $user->password = (string) $data['password'];
            }
            $user->save();

            if (! array_key_exists('use_provider_categories', $data)) {
                return 0;
            }

            return $this->updateProviderCategories->handle(
                $user,
                (bool) $data['use_provider_categories'],
                (bool) ($data['clear_provider_categories'] ?? false),
            );
        });
    }
}
