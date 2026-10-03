<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Contracts\BankProvider;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderAccountSuggestion;
use App\Domain\Banking\Enums\BankProviderName;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\ConnectionItemMismatch;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\AccountMapper;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Primeiro passo do fluxo de conexão: o widget já devolveu o item.id; aqui
 * conferimos que ele pertence a este usuário (clientUserId) e que ainda
 * não está em uso por nenhuma conexão (de qualquer usuário — external_id é
 * único por provedor em todo o sistema), criamos a conexão `pending_link`
 * e devolvemos as contas do banco com sugestão de vínculo.
 *
 * As contas do banco (ProviderAccount) ficam em `settings.pending_accounts`
 * para o vínculo (LinkAccounts) não precisar chamar o provedor de novo;
 * apagado ao vincular.
 */
final class CreateConnection
{
    public function __construct(private readonly BankProvider $provider) {}

    /**
     * @return array{connection: BankConnection, providerAccounts: list<ProviderAccountSuggestion>}
     */
    public function handle(User $user, string $itemId): array
    {
        $item = $this->provider->item($itemId);

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

        $accounts = $this->provider->accounts($itemId);

        $connection = BankConnection::create([
            'user_id' => $user->id,
            'provider' => BankProviderName::Pluggy,
            'external_id' => $itemId,
            'status' => ConnectionStatus::PendingLink,
            'institution_name' => $item->institutionName,
            'institution_logo_url' => $item->institutionLogoUrl,
            'settings' => ['pending_accounts' => array_map(self::toSettingsArray(...), $accounts)],
        ]);

        $manualAccounts = Account::query()->whereNull('connection_id')->get();

        $providerAccounts = array_map(
            fn (ProviderAccount $account) => new ProviderAccountSuggestion(
                account: $account,
                suggestedAccountId: self::suggestedAccountId($account, $item->institutionName, $manualAccounts),
            ),
            $accounts,
        );

        return ['connection' => $connection, 'providerAccounts' => $providerAccounts];
    }

    public static function clientUserId(User $user): string
    {
        return 'user:'.$user->id;
    }

    /**
     * @return array<string, mixed>
     */
    private static function toSettingsArray(ProviderAccount $account): array
    {
        return [
            'id' => $account->id,
            'kind' => $account->kind,
            'name' => $account->name,
            'number' => $account->number,
            'currency' => $account->currency,
            'balance_cents' => $account->balanceCents,
            'credit_limit_cents' => $account->creditLimitCents,
            'closing_day' => $account->closingDay,
            'due_day' => $account->dueDay,
        ];
    }

    /**
     * Sugestão de vínculo: uma conta manual (sem conexão) do mesmo tipo e
     * moeda, cujo nome contém o nome do banco ou os últimos dígitos do
     * número da conta do provedor. Primeira que bater, nenhuma garantia de
     * ser a "melhor" — é só um ponto de partida para o usuário confirmar.
     *
     * @param  Collection<int, Account>  $manualAccounts
     */
    private static function suggestedAccountId(ProviderAccount $account, ?string $institutionName, Collection $manualAccounts): ?int
    {
        $type = AccountMapper::accountTypeFor($account->kind);
        $numberTail = self::lastDigits($account->number);

        foreach ($manualAccounts as $manual) {
            if ($manual->type !== $type || $manual->currency !== $account->currency) {
                continue;
            }

            if ($institutionName !== null && Str::contains($manual->name, $institutionName, true)) {
                return $manual->id;
            }

            if ($numberTail !== null && Str::contains($manual->name, $numberTail)) {
                return $manual->id;
            }
        }

        return null;
    }

    private static function lastDigits(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $number) ?? '';

        return $digits !== '' ? substr($digits, -4) : null;
    }
}
