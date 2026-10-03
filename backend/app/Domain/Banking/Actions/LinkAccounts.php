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
 * Segundo passo do fluxo de conexão (vínculo inicial, conexão `pending_link`)
 * ou vínculo de contas novas que o banco passou a reportar depois (conexão
 * já `active`, `settings.unlinked_accounts` — ver
 * App\Domain\Banking\Actions\SyncAccounts): para cada conta do banco, cria
 * uma conta nova ou vincula a uma conta manual existente (o formato do
 * pedido já foi validado pelo App\Http\Requests\Banking\LinkAccountsRequest
 * — cobertura, tipo e moeda; cobertura total só é exigida no vínculo
 * inicial, uma conexão `active` aceita vincular só algumas das contas
 * pendentes por vez). Tudo numa transação com `lockForUpdate` na conexão:
 * dois POSTs de vínculo em paralelo não podem ambos passar pela checagem
 * de status e criar contas em duplicidade.
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

            if (! in_array($locked->status, [ConnectionStatus::PendingLink, ConnectionStatus::Active], true)) {
                throw new ConnectionNotPendingLink;
            }

            $isInitialLink = $locked->status === ConnectionStatus::PendingLink;
            $settingsKey = $isInitialLink ? 'pending_accounts' : 'unlinked_accounts';

            /** @var list<array<string, mixed>> $pendingRows */
            $pendingRows = $locked->settings[$settingsKey] ?? [];
            $pendingByExternalId = [];

            foreach (PendingProviderAccounts::fromSettings($pendingRows) as $account) {
                $pendingByExternalId[$account->id] = $account;
            }

            if ($pendingByExternalId === []) {
                // Vínculo inicial já feito (pending_accounts não existe
                // mais) ou conexão active sem nenhuma conta nova do banco
                // para vincular: nada a fazer, e silenciar isso com 200
                // esconderia um pedido que não bate com a realidade atual.
                throw new ConnectionNotPendingLink;
            }

            $linkedExternalIds = [];

            foreach ($links as $link) {
                $providerAccount = $pendingByExternalId[$link['external_id']] ?? null;

                if ($providerAccount === null) {
                    // Já validado no FormRequest (Rule::in dos external_ids
                    // pendentes); chegar aqui seria um estado inconsistente
                    // entre a validação e o settings lido sob lock — melhor
                    // ignorar a linha do que criar algo a partir de nada.
                    continue;
                }

                $linkedExternalIds[] = $providerAccount->id;

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

            $locked->update([
                'status' => ConnectionStatus::Active,
                'settings' => $this->settingsAfterLinking($locked, $settingsKey, $pendingRows, $linkedExternalIds),
            ]);

            return $locked;
        });
    }

    /**
     * Vínculo inicial: apaga `pending_accounts` por completo (o FormRequest
     * já exige cobertura total). Conexão já `active`: tira de
     * `unlinked_accounts` só as contas vinculadas agora — o que ainda não
     * foi vinculado continua lá, para um próximo vínculo parcial.
     *
     * @param  list<array<string, mixed>>  $pendingRows
     * @param  list<string>  $linkedExternalIds
     * @return array<string, mixed>|null
     */
    private function settingsAfterLinking(BankConnection $connection, string $settingsKey, array $pendingRows, array $linkedExternalIds): ?array
    {
        $settings = $connection->settings ?? [];

        if ($settingsKey === 'pending_accounts') {
            unset($settings['pending_accounts']);

            return $settings === [] ? null : $settings;
        }

        $remaining = array_values(array_filter(
            $pendingRows,
            fn (array $row) => ! in_array($row['id'], $linkedExternalIds, true),
        ));

        if ($remaining === []) {
            unset($settings['unlinked_accounts']);
        } else {
            $settings['unlinked_accounts'] = $remaining;
        }

        return $settings === [] ? null : $settings;
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
