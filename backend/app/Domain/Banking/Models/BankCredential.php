<?php

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\BankProviderName;
use App\Models\Concerns\BelongsToUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\BankCredentialFactory;
use Illuminate\Contracts\Encryption\DecryptException;
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
     * usuário autenticado quanto, em tese, para outro. Uma linha verificada
     * que não descriptografa mais (ex.: APP_KEY trocada) conta como não
     * configurada, mesmo com verified_at preenchido — ver readableClientId().
     */
    public static function isVerifiedFor(User $user): bool
    {
        $credential = static::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->whereNotNull('verified_at')
            ->first();

        return self::readableClientId($credential) !== null;
    }

    /**
     * sha1 do client_id em caixa baixa — nunca o client_id em claro — usado
     * para amarrar App\Domain\Banking\Models\BankConnection à credencial que
     * a criou/adotou (ver BankConnection::$credential_fingerprint) sem guardar
     * o client_id fora desta tabela.
     */
    public static function fingerprint(string $clientId): string
    {
        return sha1(mb_strtolower($clientId));
    }

    /**
     * client_id em claro de $credential, ou null quando não há credencial ou
     * a linha não descriptografa mais (client_id OU client_secret
     * corrompidos — lê os dois para decidir, mesmo só precisando do
     * client_id, porque uma linha com client_secret ilegível também não
     * serve mais para nada). Compartilhado por SaveBankCredentials,
     * DeleteBankCredentials e isVerifiedFor() para não reimplementar a
     * mesma checagem em cada um.
     */
    public static function readableClientId(?self $credential): ?string
    {
        if ($credential === null) {
            return null;
        }

        try {
            $clientId = $credential->client_id;
            // @phpstan-ignore expr.resultUnused (lê client_secret só para forçar a descriptografia e detectar corrupção também nele; o valor em si não é usado aqui)
            $credential->client_secret;
            // @phpstan-ignore catch.neverThrown (falso positivo: Larastan não enxerga que o cast `encrypted` pode lançar ao descriptografar; cobre uma APP_KEY trocada ou dado corrompido)
        } catch (DecryptException) {
            return null;
        }

        return $clientId;
    }

    /**
     * Fingerprint (ver fingerprint()) da credencial atual de $user, ou null
     * sem credencial cadastrada ou com linha ilegível — usado por
     * App\Domain\Banking\Actions\CreateConnection (grava na conexão nova) e
     * App\Domain\Banking\Jobs\SyncConnection (adota numa conexão legada, com
     * fingerprint ainda null); nos dois casos só é chamado depois que outra
     * checagem (EnsureBankingEnabled ou BankProviderFactory::for()) já
     * confirmou que a credencial atual está verificada.
     */
    public static function currentFingerprintFor(User $user): ?string
    {
        $credential = static::query()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->first();

        $clientId = self::readableClientId($credential);

        return $clientId !== null ? self::fingerprint($clientId) : null;
    }
}
