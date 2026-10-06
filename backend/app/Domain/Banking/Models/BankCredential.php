<?php

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\BankProviderName;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\BankCredentialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Credenciais da Pluggy (client_id/client_secret) cadastradas pelo próprio
 * usuário em Configurações — uma por usuário e provedor
 * (App\Domain\Banking\Contracts\BankProviderFactory lê daqui para montar o
 * provedor de cada usuário). client_id e client_secret ficam criptografados
 * (cast `encrypted`, chave APP_KEY) e nunca saem deste model ($hidden);
 * client_id_hint guarda só os últimos 4 caracteres do client id em claro,
 * para a tela mostrar algo como "••••3be8" sem precisar descriptografar.
 *
 * @property BankProviderName $provider
 * @property string $client_id
 * @property string $client_secret
 * @property string $client_id_hint
 * @property CarbonImmutable|null $verified_at
 */
class BankCredential extends Model
{
    use BelongsToUser;

    /** @use HasFactory<BankCredentialFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'provider', 'client_id', 'client_secret', 'client_id_hint', 'verified_at',
    ];

    /** @var list<string> */
    protected $hidden = ['client_id', 'client_secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => BankProviderName::class,
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'verified_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): BankCredentialFactory
    {
        return BankCredentialFactory::new();
    }
}
