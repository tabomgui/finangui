<?php

namespace App\Domain\Banking\Providers\Pluggy;

use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Errors\BankingDisabled;
use App\Domain\Banking\Models\BankCredential;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

/**
 * Lê a credencial do próprio usuário e monta um PluggyProvider com ela.
 * where('user_id', ...) explícito, além do global scope de BelongsToUser
 * (que já exige um usuário autenticado batendo com $user — chamar for()
 * fora de uma request autenticada ou de dentro de
 * App\Support\UserContext::run() falha fechado, como qualquer outro model
 * com esse trait): a dupla checagem documenta a intenção, não é
 * redundância por acaso. Sem credencial verificada, o usuário ainda não
 * "ligou" a integração; uma credencial que não descriptografa mais (ex.:
 * APP_KEY trocada, dado corrompido) é tratada do mesmo jeito, nunca
 * propagando o erro de criptografia — ver
 * App\Domain\Banking\Errors\BankingDisabled.
 */
final class PluggyProviderFactory implements BankProviderFactory
{
    public function for(User $user): BankProvider
    {
        $credential = BankCredential::query()
            ->where('user_id', $user->id)
            ->where('provider', BankProviderName::Pluggy)
            ->whereNotNull('verified_at')
            ->first();

        if ($credential === null) {
            throw new BankingDisabled;
        }

        try {
            $clientId = $credential->client_id;
            $clientSecret = $credential->client_secret;
            // @phpstan-ignore catch.neverThrown (falso positivo: Larastan não enxerga que o cast `encrypted` pode lançar ao descriptografar; cobre uma APP_KEY trocada ou dado corrompido, ver tests/Feature/Banking/PluggyProviderFactoryDecryptFailureTest.php)
        } catch (DecryptException) {
            // Nunca loga o valor cru (nem cifrado): só o id do usuário, o
            // bastante para investigar sem arriscar vazar segredo nenhum.
            Log::warning('Pluggy: credencial do usuário não descriptografa mais; tratando como sem credenciais.', [
                'user_id' => $user->id,
            ]);

            throw new BankingDisabled;
        }

        /** @var string $baseUrl */
        $baseUrl = config('services.pluggy.base_url');

        return new PluggyProvider($clientId, $clientSecret, $baseUrl, $user->id);
    }
}
