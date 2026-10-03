<?php

namespace App\Domain\Banking\Support;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Data\ProviderAccountSuggestion;
use App\Domain\Banking\Models\BankConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lê e grava `settings.pending_accounts` — as contas do banco ainda não
 * vinculadas de uma conexão `pending_link` (ver
 * App\Domain\Banking\Actions\CreateConnection) — e calcula a sugestão de
 * vínculo. Usada na criação da conexão e de novo em
 * App\Http\Resources\BankConnectionResource, para a tela retomar o vínculo
 * sem chamar o provedor outra vez.
 */
final class PendingProviderAccounts
{
    /**
     * Só os últimos 4 dígitos do número de cada conta ficam gravados: o
     * suficiente para a sugestão de vínculo e para preencher `last_four`
     * de um cartão novo (ver AccountMapper); o número completo do banco
     * não precisa ficar guardado no app.
     *
     * @param  list<ProviderAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    public static function toSettings(array $accounts): array
    {
        return array_map(fn (ProviderAccount $account) => [
            'id' => $account->id,
            'kind' => $account->kind,
            'name' => $account->name,
            'number' => self::lastDigits($account->number),
            'currency' => $account->currency,
            'balance_cents' => $account->balanceCents,
            'credit_limit_cents' => $account->creditLimitCents,
            'closing_day' => $account->closingDay,
            'due_day' => $account->dueDay,
        ], $accounts);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<ProviderAccount>
     */
    public static function fromSettings(array $rows): array
    {
        return array_map(self::fromRow(...), $rows);
    }

    /**
     * Sugestão de vínculo para cada conta pendente da conexão: uma conta
     * manual (sem conexão, não arquivada) do mesmo tipo e moeda, cujo nome
     * contém o nome do banco ou os últimos dígitos do número da conta do
     * provedor. Cada conta manual só é sugerida a uma conta do banco por
     * vez — a primeira conta do banco que bater "ganha" a sugestão, as
     * seguintes não podem repetir a mesma conta manual.
     *
     * $settingsKey: `pending_accounts` (vínculo inicial, conexão
     * `pending_link`) ou `unlinked_accounts` (contas do banco que
     * apareceram depois do vínculo inicial, numa conexão já `active` — ver
     * App\Domain\Banking\Actions\SyncAccounts).
     *
     * @return list<ProviderAccountSuggestion>
     */
    public static function suggestionsFor(BankConnection $connection, string $settingsKey = 'pending_accounts'): array
    {
        // settings pode ser null (conexão já vinculada — LinkAccounts
        // apaga pending_accounts, e pode até zerar settings por completo):
        // indexar null diretamente ainda funciona em PHP, mas com aviso.
        $settings = $connection->settings ?? [];
        /** @var list<array<string, mixed>> $rows */
        $rows = $settings[$settingsKey] ?? [];
        $accounts = self::fromSettings($rows);

        if ($accounts === []) {
            return [];
        }

        $manualAccounts = Account::query()
            ->whereNull('connection_id')
            ->where('is_archived', false)
            ->get();

        $usedManualAccountIds = [];
        $suggestions = [];

        foreach ($accounts as $account) {
            $suggestedId = self::suggestedAccountId($account, $connection->institution_name, $manualAccounts, $usedManualAccountIds);

            if ($suggestedId !== null) {
                $usedManualAccountIds[] = $suggestedId;
            }

            $suggestions[] = new ProviderAccountSuggestion($account, $suggestedId);
        }

        return $suggestions;
    }

    /**
     * @param  Collection<int, Account>  $manualAccounts
     * @param  list<int>  $usedManualAccountIds
     */
    private static function suggestedAccountId(ProviderAccount $account, ?string $institutionName, Collection $manualAccounts, array $usedManualAccountIds): ?int
    {
        $type = AccountMapper::accountTypeFor($account->kind);

        foreach ($manualAccounts as $manual) {
            if (in_array($manual->id, $usedManualAccountIds, true)) {
                continue;
            }

            if ($manual->type !== $type || $manual->currency !== $account->currency) {
                continue;
            }

            if ($institutionName !== null && Str::contains($manual->name, $institutionName, true)) {
                return $manual->id;
            }

            if ($account->number !== null && Str::contains($manual->name, $account->number)) {
                return $manual->id;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function fromRow(array $row): ProviderAccount
    {
        return new ProviderAccount(
            id: (string) $row['id'],
            kind: self::kindOf($row),
            name: (string) $row['name'],
            number: $row['number'] !== null ? (string) $row['number'] : null,
            currency: (string) $row['currency'],
            balanceCents: (int) $row['balance_cents'],
            creditLimitCents: $row['credit_limit_cents'] !== null ? (int) $row['credit_limit_cents'] : null,
            closingDay: $row['closing_day'] !== null ? (int) $row['closing_day'] : null,
            dueDay: $row['due_day'] !== null ? (int) $row['due_day'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return 'checking'|'savings'|'credit_card'
     */
    private static function kindOf(array $row): string
    {
        return match ($row['kind']) {
            'savings' => 'savings',
            'credit_card' => 'credit_card',
            default => 'checking',
        };
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
