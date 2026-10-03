<?php

namespace App\Domain\Banking\Support;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Models\BankConnection;
use Illuminate\Support\Carbon;

/**
 * Cria/atualiza contas a partir de uma conta do provedor (ProviderAccount):
 * usado no vínculo (App\Domain\Banking\Actions\LinkAccounts) e no sync
 * periódico (App\Domain\Banking\Jobs\SyncConnection). Nunca sobrescreve o
 * que o usuário já definiu (nome, cor, ícone, dias do cartão já
 * preenchidos) — só o que vem do banco (vínculo, saldo, limite).
 */
final class AccountMapper
{
    private const DEFAULT_CLOSING_DAY = 1;

    private const DEFAULT_DUE_DAY = 10;

    /**
     * Conta nova vinculada à conexão: nome/tipo/moeda do provedor,
     * abertura zero (o saldo do provedor é só conferência, nunca lançado
     * como abertura), limite e dias do cartão (dias com fallback 1/10),
     * ícone por tipo.
     */
    public function createLinked(BankConnection $connection, ProviderAccount $account): Account
    {
        $isCreditCard = $account->kind === 'credit_card';

        return Account::create([
            'name' => $account->name,
            'type' => self::accountTypeFor($account->kind),
            'currency' => $account->currency,
            'opening_balance' => 0,
            'icon' => $isCreditCard ? 'credit-card' : 'landmark',
            'credit_limit' => $isCreditCard ? $account->creditLimitCents : null,
            'closing_day' => $isCreditCard ? ($account->closingDay ?? self::DEFAULT_CLOSING_DAY) : null,
            'due_day' => $isCreditCard ? ($account->dueDay ?? self::DEFAULT_DUE_DAY) : null,
            'connection_id' => $connection->id,
            'external_id' => $account->id,
            'provider_balance' => $account->balanceCents,
            'provider_synced_at' => Carbon::now(),
        ]);
    }

    /**
     * Vincula uma conta manual já existente: só o que vem do banco
     * (vínculo e saldo informado) é gravado; nome, cor, ícone e dias do
     * cartão ficam exatamente como o usuário já definiu.
     */
    public function linkExisting(Account $existing, BankConnection $connection, ProviderAccount $account): Account
    {
        $existing->update([
            'connection_id' => $connection->id,
            'external_id' => $account->id,
            'provider_balance' => $account->balanceCents,
            'provider_synced_at' => Carbon::now(),
        ]);

        return $existing;
    }

    /**
     * Atualização de uma conta já vinculada, a cada sync: saldo e limite
     * do cartão são sempre atualizados; nome, cor, ícone e dias do cartão
     * nunca (o usuário pode ter editado depois do vínculo).
     */
    public function updateLinked(Account $existing, ProviderAccount $account): Account
    {
        $existing->update([
            'provider_balance' => $account->balanceCents,
            'provider_synced_at' => Carbon::now(),
            'credit_limit' => $account->kind === 'credit_card' ? $account->creditLimitCents : $existing->credit_limit,
        ]);

        return $existing;
    }

    /**
     * @param  'checking'|'savings'|'credit_card'  $kind
     */
    public static function accountTypeFor(string $kind): AccountType
    {
        return match ($kind) {
            'checking' => AccountType::Checking,
            'savings' => AccountType::Savings,
            'credit_card' => AccountType::CreditCard,
        };
    }
}
