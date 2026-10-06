<?php

namespace App\Domain\Banking\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\BankConnectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conexão com um banco via um provedor de open finance (Pluggy). Guarda só
 * o id do item do provedor (`external_id`); o resto (contas, saldos,
 * transações) é buscado de novo a cada sync — ver `App\Domain\Banking\Jobs\SyncConnection`.
 *
 * `credential_fingerprint` (sha1 do client_id em caixa baixa — nunca o
 * client_id em si — ver `App\Domain\Banking\Models\BankCredential::fingerprint()`)
 * amarra a conexão à credencial da Pluggy que a criou: `CreateConnection`
 * grava a da credencial atual do usuário; null numa conexão existente
 * (criada sob a conta global antiga, por variável de ambiente) é adotado
 * pela própria credencial atual no primeiro sync que terminar com sucesso
 * (`SyncConnection`). `SaveBankCredentials`/`DeleteBankCredentials` só
 * contam como "em uso" a conexão cujo fingerprint bate com o da credencial
 * atual — null ou de outra conta nunca bloqueia.
 *
 * @property BankProviderName $provider
 * @property ConnectionStatus $status
 * @property string|null $credential_fingerprint
 * @property CarbonImmutable|null $last_synced_at
 * @property array<string, mixed>|null $settings
 */
class BankConnection extends Model
{
    use BelongsToUser;

    /** @use HasFactory<BankConnectionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'provider', 'external_id', 'status', 'institution_name',
        'institution_logo_url', 'last_synced_at', 'last_error', 'settings',
        'credential_fingerprint',
    ];

    /**
     * Espelha o default da migration: sem isso, um create() sem a chave
     * fica null em memória até um refresh, mesmo a coluna sendo default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending_link',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => BankProviderName::class,
            'status' => ConnectionStatus::class,
            'last_synced_at' => 'immutable_datetime',
            'settings' => 'array',
        ];
    }

    protected static function newFactory(): BankConnectionFactory
    {
        return BankConnectionFactory::new();
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'connection_id');
    }
}
