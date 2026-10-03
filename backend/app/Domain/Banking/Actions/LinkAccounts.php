<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\ConnectionNotPendingLink;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\AccountMapper;
use Illuminate\Support\Facades\DB;

/**
 * Segundo passo do fluxo de conexão: para cada conta do banco, cria uma
 * conta nova ou vincula a uma conta manual existente (já validado pelo
 * App\Http\Requests\Banking\LinkAccountsRequest — tipo, moeda, dono e
 * "sem conexão"). Tudo numa transação com `lockForUpdate` na conexão: dois
 * POSTs de vínculo em paralelo não podem ambos passar pela checagem de
 * `pending_link` e criar contas em duplicidade.
 */
final class LinkAccounts
{
    public function __construct(private readonly AccountMapper $accountMapper) {}

    /**
     * @param  list<array{external_id: string, account_id: int|null}>  $links
     */
    public function handle(BankConnection $connection, array $links): BankConnection
    {
        return DB::transaction(function () use ($connection, $links) {
            /** @var BankConnection $locked */
            $locked = BankConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ConnectionStatus::PendingLink) {
                throw new ConnectionNotPendingLink;
            }

            /** @var list<array<string, mixed>> $pending */
            $pending = $locked->settings['pending_accounts'] ?? [];
            $pendingByExternalId = [];

            foreach ($pending as $row) {
                $pendingByExternalId[(string) $row['id']] = $row;
            }

            foreach ($links as $link) {
                $row = $pendingByExternalId[$link['external_id']] ?? null;

                if ($row === null) {
                    // Já validado no FormRequest (Rule::in dos external_ids
                    // pendentes); chegar aqui seria um estado inconsistente
                    // entre a validação e o settings lido sob lock — melhor
                    // ignorar a linha do que criar algo a partir de nada.
                    continue;
                }

                $providerAccount = self::providerAccountFromRow($row);

                if ($link['account_id'] !== null) {
                    /** @var Account $account */
                    $account = Account::query()->whereKey($link['account_id'])->firstOrFail();
                    $this->accountMapper->linkExisting($account, $locked, $providerAccount);
                } else {
                    $this->accountMapper->createLinked($locked, $providerAccount);
                }
            }

            $locked->update([
                'status' => ConnectionStatus::Active,
                'settings' => null,
            ]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function providerAccountFromRow(array $row): ProviderAccount
    {
        return new ProviderAccount(
            id: (string) $row['id'],
            kind: self::kindFromRow($row),
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
    private static function kindFromRow(array $row): string
    {
        return match ($row['kind']) {
            'savings' => 'savings',
            'credit_card' => 'credit_card',
            default => 'checking',
        };
    }
}
