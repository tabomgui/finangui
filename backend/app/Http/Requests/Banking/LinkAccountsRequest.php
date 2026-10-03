<?php

namespace App\Http\Requests\Banking;

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Data\ProviderAccount;
use App\Domain\Banking\Enums\ConnectionStatus;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Banking\Support\AccountMapper;
use App\Domain\Banking\Support\PendingProviderAccounts;
use App\Http\Requests\ApiRequest;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * As contas do banco (e seu tipo/moeda) vêm de settings.pending_accounts,
 * gravado por App\Domain\Banking\Actions\CreateConnection — aqui só
 * validamos o formato do pedido; a gravação em si (criar/vincular conta,
 * trocar o status) é App\Domain\Banking\Actions\LinkAccounts, sob lock.
 *
 * Quando a conexão não está `pending_link` (já vinculada antes, ou nem
 * existe mais `pending_accounts`), as regras ficam soltas de propósito: a
 * checagem de cobertura/tipo/moeda não faz sentido sem as contas do banco
 * em mãos, e quem deve rejeitar o pedido é a Action (409
 * connection_not_pending_link), não um 422 de validação que mascararia a
 * causa real.
 */
final class LinkAccountsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $connection = $this->pendingConnection();

        if ($connection === null) {
            return [
                'links' => ['required', 'array'],
                'links.*.external_id' => ['required', 'string'],
                'links.*.account_id' => ['nullable', 'integer'],
            ];
        }

        $pendingByExternalId = self::pendingByExternalId($connection);
        $externalIds = $pendingByExternalId->keys()->all();

        return [
            'links' => [
                'required', 'array', 'max:'.count($externalIds),
                $this->coversAllAccounts($externalIds),
                self::accountIdsAreDistinct(),
            ],
            'links.*.external_id' => ['required', 'string', Rule::in($externalIds), 'distinct'],
            'links.*.account_id' => ['nullable', 'integer', $this->accountIsLinkable($pendingByExternalId)],
        ];
    }

    /**
     * Null quando a conexão não existe (Scramble gerando a doc sem rota
     * real), não está `pending_link`, ou está sem `pending_accounts`
     * (estado inconsistente, mas tratado do mesmo jeito solto).
     */
    private function pendingConnection(): ?BankConnection
    {
        // Scramble chama rules() fora de uma request real (sem rota) para
        // gerar a doc da API: $connection precisa ser null-safe, como em
        // UpdateStatementRequest.
        $connection = $this->route('connection');

        if (! $connection instanceof BankConnection || $connection->status !== ConnectionStatus::PendingLink) {
            return null;
        }

        return filled($connection->settings['pending_accounts'] ?? null) ? $connection : null;
    }

    /**
     * @return Collection<string, ProviderAccount>
     */
    private static function pendingByExternalId(BankConnection $connection): Collection
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $connection->settings['pending_accounts'] ?? [];

        return collect(PendingProviderAccounts::fromSettings($rows))->keyBy(fn (ProviderAccount $account) => $account->id);
    }

    /**
     * @param  list<string>  $externalIds
     */
    private function coversAllAccounts(array $externalIds): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($externalIds): void {
            $provided = collect(is_array($value) ? $value : [])->pluck('external_id')->filter()->all();
            $missing = array_diff($externalIds, $provided);

            if ($missing !== []) {
                $fail('Vincule todas as contas do banco antes de continuar.');
            }
        };
    }

    /**
     * `distinct` (a regra nativa do Laravel) não serve aqui: para ela,
     * `in_array(null, [null, null])` é `true` (comparação não estrita), e
     * marcaria duas linhas "criar conta nova" (account_id null) como
     * duplicadas — exatamente o caso comum que precisa ser permitido.
     */
    private static function accountIdsAreDistinct(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $ids = collect(is_array($value) ? $value : [])->pluck('account_id')->filter(fn ($id) => $id !== null)->all();

            if (count($ids) !== count(array_unique($ids))) {
                $fail('Cada conta só pode ser vinculada a uma conta do banco.');
            }
        };
    }

    /**
     * @param  Collection<string, ProviderAccount>  $pendingByExternalId
     */
    private function accountIsLinkable(Collection $pendingByExternalId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($pendingByExternalId): void {
            if ($value === null) {
                return;
            }

            if (preg_match('/^links\.(\d+)\.account_id$/', $attribute, $matches) !== 1) {
                return;
            }

            $externalId = $this->input("links.{$matches[1]}.external_id");
            $pending = is_string($externalId) ? $pendingByExternalId->get($externalId) : null;

            if ($pending === null) {
                // external_id desta linha já falha sozinho (Rule::in); não
                // dá pra conferir tipo/moeda sem saber qual conta do banco
                // esta linha se refere.
                return;
            }

            /** @var Account|null $account */
            $account = Account::query()->whereKey($value)->first();

            if ($account === null) {
                $fail('Conta não encontrada.');

                return;
            }

            if ($account->connection_id !== null) {
                $fail('Esta conta já está conectada a um banco.');

                return;
            }

            if ($account->is_archived) {
                $fail('Esta conta está arquivada.');

                return;
            }

            if ($account->type !== AccountMapper::accountTypeFor($pending->kind)) {
                $fail('O tipo da conta não corresponde ao da conta do banco.');

                return;
            }

            if ($account->currency !== $pending->currency) {
                $fail('A moeda da conta não corresponde à da conta do banco.');
            }
        };
    }
}
