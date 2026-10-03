<?php

namespace App\Domain\Banking\Support;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
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
            'last_four' => $isCreditCard ? self::last4($account->number) : null,
            'connection_id' => $connection->id,
            'external_id' => $account->id,
            'provider_balance' => self::appBalanceCents($account),
            'provider_synced_at' => Carbon::now(),
        ]);
    }

    /**
     * Vincula uma conta manual já existente: só o que vem do banco
     * (vínculo e saldo informado) é gravado; nome, cor, ícone e dias do
     * cartão ficam exatamente como o usuário já definiu. `last_four` só é
     * preenchido quando a conta manual ainda não tinha um (criada antes de
     * vincular, sem os últimos dígitos) — nunca sobrescreve o que o
     * usuário já informou.
     *
     * `provider_sync_from` guarda a partir de quando o sync bancário pode
     * importar transações desta conta: a data do lançamento mais antigo já
     * existente (ou a criação da conta, sem nenhum lançamento) — o que é
     * anterior a isso já está refletido no saldo inicial que o usuário
     * lançou à mão. Uma conta criada pelo vínculo (createLinked) nunca tem
     * esse piso — não há histórico prévio a proteger.
     */
    public function linkExisting(Account $existing, BankConnection $connection, ProviderAccount $account): Account
    {
        $attributes = [
            'connection_id' => $connection->id,
            'external_id' => $account->id,
            'provider_balance' => self::appBalanceCents($account),
            'provider_synced_at' => Carbon::now(),
            'provider_sync_from' => $this->syncFloorFor($existing),
        ];

        if ($account->kind === 'credit_card' && blank($existing->last_four)) {
            $attributes['last_four'] = self::last4($account->number);
        }

        $existing->update($attributes);

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
            'provider_balance' => self::appBalanceCents($account),
            'provider_synced_at' => Carbon::now(),
            // ?? $existing->credit_limit (não só o ternário por tipo): o
            // banco pode mandar o sync sem o limite desta vez (campo
            // omitido/null) — nesse caso mantém o limite que já tínhamos,
            // em vez de apagar.
            'credit_limit' => $account->kind === 'credit_card' ? ($account->creditLimitCents ?? $existing->credit_limit) : $existing->credit_limit,
        ]);

        return $existing;
    }

    /**
     * Saldo do provedor traduzido para o sinal do app: em cartão, o saldo
     * do provedor é o valor devido (positivo); o saldo do app é negativo
     * quando há dívida — fonte única do sinal, usada em createLinked,
     * linkExisting e updateLinked (nunca inverte em dois lugares
     * diferentes, nem deixa de inverter em um deles).
     */
    public static function appBalanceCents(ProviderAccount $account): int
    {
        return $account->kind === 'credit_card' ? -$account->balanceCents : $account->balanceCents;
    }

    /**
     * Ajuste de abertura de uma conta criada pelo vínculo (nunca de uma
     * conta manual vinculada depois — essa já tem seu próprio
     * opening_balance e provider_sync_from, ver linkExisting): depois do
     * primeiro sync bem-sucedido de transações da conta,
     * `opening_balance = saldo informado pelo banco − Σ(lançadas, não
     * ignoradas)`, para o saldo do app bater com o do banco sem contar o
     * histórico duas vezes. Roda só uma vez (provider_opening_set_at);
     * chamado por App\Domain\Banking\Jobs\SyncConnection depois de
     * SyncTransactions.
     *
     * Nunca em cartão: o saldo informado pelo provedor para um cartão é o
     * valor devido, que já inclui compras futuras ainda não faturadas
     * (parceladas ou da fatura aberta) — bem diferente de Σ(lançadas), que
     * só soma o que já está lançado. Calcular a abertura a partir disso
     * contaria compromissos futuros como se fossem dívida já existente no
     * início. Cartão sempre começa com abertura zero.
     */
    public function settleOpeningBalance(Account $account): void
    {
        if ($account->isCreditCard()
            || $account->provider_sync_from !== null
            || $account->provider_opening_set_at !== null
            || $account->provider_balance === null) {
            return;
        }

        $postedNet = (int) Transaction::query()
            ->where('account_id', $account->id)
            ->where('status', TransactionStatus::Posted->value)
            ->where('is_ignored', false)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END), 0) as net")
            ->value('net');

        $account->update([
            'opening_balance' => $account->provider_balance->cents - $postedNet,
            'provider_opening_set_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Marca que o histórico inicial desta conta (365 dias, ou desde
     * provider_sync_from) já foi buscado pelo menos uma vez — depois disso
     * os próximos syncs usam createdAtFrom (incremental) em vez de dateFrom
     * de novo. Idempotente: não sobrescreve uma marca já existente.
     */
    public function markHistorySynced(Account $account): void
    {
        if ($account->provider_history_synced_at !== null) {
            return;
        }

        $account->update(['provider_history_synced_at' => CarbonImmutable::now()]);
    }

    /**
     * Piso de sync (provider_sync_from) de uma conta manual que está sendo
     * vinculada agora: a data do lançamento mais antigo já existente, ou a
     * data de criação da conta, sem nenhum lançamento.
     */
    private function syncFloorFor(Account $existing): string
    {
        $firstTransactionDate = Transaction::query()->where('account_id', $existing->id)->min('date');

        if (is_string($firstTransactionDate)) {
            return $firstTransactionDate;
        }

        // created_at é gravado em UTC; sem conta nenhuma, a data de
        // criação "local" (fuso do app, não UTC) é o que de fato importa
        // aqui — um create() às 23h de São Paulo já é dia seguinte em UTC.
        return $existing->created_at->setTimezone(config('app.timezone'))->toDateString();
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

    /**
     * Últimos 4 dígitos do número da conta do provedor (já pode chegar com
     * só 4 — ver App\Domain\Banking\Support\PendingProviderAccounts, que
     * mascara antes de gravar em settings). Null quando não há dígitos
     * suficientes, em vez de gravar algo mais curto que `last_four` (char(4)).
     */
    private static function last4(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $number) ?? '';

        return strlen($digits) >= 4 ? substr($digits, -4) : null;
    }
}
