<?php

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\BankProviderName;
use App\Models\Concerns\BelongsToUser;
use App\Models\User;
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
 * client_id_hint guarda só os últimos 4 caracteres do client id em claro
 * (sem máscara); a tela monta algo como "••••3be8" na hora de exibir, sem
 * precisar descriptografar.
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

    /**
     * Único ponto que decide se um usuário "ligou" a integração bancária —
     * usado por App\Http\Resources\UserResource (banking_enabled) e
     * App\Http\Middleware\EnsureBankingEnabled, sem nenhum dos dois precisar
     * montar um provedor (e arriscar o problema de descriptografia tratado
     * em App\Domain\Banking\Providers\Pluggy\PluggyProviderFactory) só para
     * checar isso. withoutGlobalScopes(): chamado tanto para o próprio
     * usuário autenticado quanto, em tese, para outro.
     */
    public static function isVerifiedFor(User $user): bool
    {
        return static::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->whereNotNull('verified_at')
            ->exists();
    }
}
