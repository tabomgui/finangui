<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\AccountMapper;
use App\Domain\Banking\Support\PendingProviderAccounts;

/**
 * Primeiro passo do sync periódico (App\Domain\Banking\Jobs\SyncConnection):
 * atualiza saldo informado e limite de cada conta já vinculada à conexão —
 * nunca nome, cor, ícone ou dias do cartão, que o usuário pode ter editado
 * depois do vínculo (ver AccountMapper::updateLinked()). Uma conta do banco
 * que ainda não está vinculada (apareceu depois do vínculo inicial) não é
 * criada sozinha: fica em `settings.unlinked_accounts`, para a tela
 * oferecer o vínculo.
 */
final class SyncAccounts
{
    public function __construct(private readonly AccountMapper $accountMapper) {}

    /**
     * @param  list<ProviderAccount>  $accounts
     */
    public function handle(BankConnection $connection, array $accounts): void
    {
        $linkedByExternalId = $connection->accounts()->get()->keyBy('external_id');
        $unlinked = [];

        foreach ($accounts as $providerAccount) {
            $linked = $linkedByExternalId->get($providerAccount->id);

            if ($linked === null) {
                $unlinked[] = $providerAccount;

                continue;
            }

            $this->accountMapper->updateLinked($linked, $this->forComparison($providerAccount));
        }

        $this->updateUnlinkedSettings($connection, $unlinked);
    }

    /**
     * Em cartão, o saldo do provedor é o valor devido (positivo); o saldo
     * do app é negativo quando há dívida — inverte o sinal aqui, só para
     * esta comparação/gravação, sem alterar o DTO original (usado também
     * para montar `settings.unlinked_accounts`, que precisa do saldo "como
     * o banco informou").
     */
    private function forComparison(ProviderAccount $account): ProviderAccount
    {
        if ($account->kind !== 'credit_card') {
            return $account;
        }

        return new ProviderAccount(
            id: $account->id,
            kind: $account->kind,
            name: $account->name,
            number: $account->number,
            currency: $account->currency,
            balanceCents: -$account->balanceCents,
            creditLimitCents: $account->creditLimitCents,
            closingDay: $account->closingDay,
            dueDay: $account->dueDay,
        );
    }

    /**
     * @param  list<ProviderAccount>  $unlinked
     */
    private function updateUnlinkedSettings(BankConnection $connection, array $unlinked): void
    {
        $settings = $connection->settings ?? [];

        if ($unlinked === []) {
            if (! array_key_exists('unlinked_accounts', $settings)) {
                return;
            }

            unset($settings['unlinked_accounts']);
        } else {
            $settings['unlinked_accounts'] = PendingProviderAccounts::toSettings($unlinked);
        }

        $connection->update(['settings' => $settings === [] ? null : $settings]);
    }
}
