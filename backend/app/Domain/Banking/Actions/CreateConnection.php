<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Contracts\BankProviderFactory;
use App\Domain\Banking\Data\ProviderAccountSuggestion;
use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\ConnectionItemMismatch;
use App\Domain\Banking\Errors\ConnectionWithoutAccounts;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\PendingProviderAccounts;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Primeiro passo do fluxo de conexão: o widget já devolveu o item.id; aqui
 * conferimos que ele pertence a este usuário (clientUserId) e que ainda
 * não está em uso por nenhuma conexão (de qualquer usuário — external_id é
 * único por provedor em todo o sistema), criamos a conexão `pending_link`
 * e devolvemos as contas do banco com sugestão de vínculo.
 *
 * As contas do banco ficam em `settings.pending_accounts` (ver
 * App\Domain\Banking\Support\PendingProviderAccounts) para o vínculo
 * (LinkAccounts) e a tela de retomada (BankConnectionResource) não
 * precisarem chamar o provedor de novo; apagado ao vincular.
 */
final class CreateConnection
{
    public function __construct(private readonly BankProviderFactory $providerFactory) {}

    /**
     * @return array{connection: BankConnection, providerAccounts: list<ProviderAccountSuggestion>}
     */
    public function handle(User $user, string $itemId): array
    {
        $provider = $this->providerFactory->for($user);

        $item = $provider->item($itemId);

        if ($item->clientUserId !== self::clientUserId($user)) {
            throw new ConnectionItemMismatch;
        }

        $alreadyConnected = BankConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', BankProviderName::Pluggy)
            ->where('external_id', $itemId)
            ->exists();

        if ($alreadyConnected) {
            throw new ConnectionItemMismatch;
        }

        $accounts = $provider->accounts($itemId);

        if ($accounts === []) {
            throw new ConnectionWithoutAccounts;
        }

        try {
            $connection = BankConnection::create([
                'user_id' => $user->id,
                'provider' => BankProviderName::Pluggy,
                'external_id' => $itemId,
                'status' => ConnectionStatus::PendingLink,
                'institution_name' => $item->institutionName,
                'institution_logo_url' => $item->institutionLogoUrl,
                'settings' => ['pending_accounts' => PendingProviderAccounts::toSettings($accounts)],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Corrida: outra requisição criou a conexão deste item entre o
            // exists() acima e este create() (ex.: duas abas abrindo o
            // widget para o mesmo item ao mesmo tempo). O unique de
            // (provider, external_id) pega o caso raro que o exists() não
            // pegou.
            throw new ConnectionItemMismatch;
        }

        return ['connection' => $connection, 'providerAccounts' => PendingProviderAccounts::suggestionsFor($connection)];
    }

    public static function clientUserId(User $user): string
    {
        return 'user:'.$user->id;
    }
}
