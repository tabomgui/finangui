<?php

namespace App\Http\Resources;

use App\Domain\Banking\Models\BankCredential;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'has_password' => $this->password !== null,
            'google_linked' => $this->google_id !== null,
            // Configuração da instância (ainda não é por usuário); fica aqui para o
            // frontend não fixar 'BRL' ao decidir quando mostrar a moeda de uma conta.
            'primary_currency' => (string) config('finangui.primary_currency'),
            // Sem credenciais da Pluggy verificadas (e legíveis) cadastradas por
            // este usuário, o frontend leva o botão "Conectar banco" para o card
            // de Configurações em vez de abrir o widget da Pluggy.
            'banking_enabled' => BankCredential::isVerifiedFor($this->resource),
        ];
    }
}
