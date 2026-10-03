<?php

namespace App\Domain\Banking\Actions;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Errors\AccountNoLongerLinkable;
use App\Domain\Banking\Errors\ConnectionNotPendingLink;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\AccountMapper;
use App\Domain\Banking\Support\PendingProviderAccounts;
use Illuminate\Support\Facades\DB;

/**
 * Segundo passo do fluxo de conexão: para cada conta do banco, cria uma
 * conta nova ou vincula a uma conta manual existente (o formato do pedido
 * já foi validado pelo App\Http\Requests\Banking\LinkAccountsRequest —
 * cobertura, tipo e moeda). Tudo numa transação com `lockForUpdate` na
 * conexão: dois POSTs de vínculo em paralelo não podem ambos passar pela
 * checagem de `pending_link` e criar contas em duplicidade.
 */
final class LinkAccounts
{
    public function __construct(private readonly AccountMapper $accountMapper) {}

    /**
     * @param  list<array{external_id: string, account_id?: int|null}>  $links
     */
    public function handle(BankConnection $connection, array $links): BankConnection
    {
        return DB::transaction(function () use ($connection, $links) {
            /** @var BankConnection $locked */
            $locked = BankConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ConnectionStatus::PendingLink) {
                throw new ConnectionNotPendingLink;
            }

            /** @var list<array<string, mixed>> $pendingRows */
            $pendingRows = $locked->settings['pending_accounts'] ?? [];
            $pendingByExternalId = [];

            foreach (PendingProviderAccounts::fromSettings($pendingRows) as $account) {
                $pendingByExternalId[$account->id] = $account;
            }

            foreach ($links as $link) {
                $providerAccount = $pendingByExternalId[$link['external_id']] ?? null;

                if ($providerAccount === null) {
                    // Já validado no FormRequest (Rule::in dos external_ids
                    // pendentes); chegar aqui seria um estado inconsistente
                    // entre a validação e o settings lido sob lock — melhor
                    // ignorar a linha do que criar algo a partir de nada.
                    continue;
                }

                // ?? null (não $link['account_id'] direto): a chave pode
                // nem vir no corpo quando o cliente só manda external_id
                // para "criar conta nova" (o FormRequest aceita omitir, não
                // só null explícito).
                $accountId = $link['account_id'] ?? null;

                if ($accountId !== null) {
                    $this->linkExistingLocked($locked, $accountId, $providerAccount);
                } else {
                    $this->accountMapper->createLinked($locked, $providerAccount);
                }
            }

            $settings = $locked->settings ?? [];
            unset($settings['pending_accounts']);

            $locked->update([
                'status' => ConnectionStatus::Active,
                'settings' => $settings === [] ? null : $settings,
            ]);

            return $locked;
        });
    }

    /**
     * Confere de novo, sob a trava da própria conta (não só a da conexão),
     * que ela ainda está disponível: entre o FormRequest validar e esta
     * transação travar, outra requisição pode ter conectado a mesma conta
     * ou ela pode ter sido arquivada.
     */
    private function linkExistingLocked(BankConnection $connection, int $accountId, ProviderAccount $providerAccount): void
    {
        /** @var Account|null $account */
        $account = Account::query()
            ->whereKey($accountId)
            ->whereNull('connection_id')
            ->where('is_archived', false)
            ->lockForUpdate()
            ->first();

        if ($account === null) {
            throw new AccountNoLongerLinkable;
        }

        $this->accountMapper->linkExisting($account, $connection, $providerAccount);
    }
}
