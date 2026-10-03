<?php

namespace App\Http\Resources;

use App\Domain\Banking\Data\ProviderAccountSuggestion;
use App\Domain\Banking\Support\AccountMapper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma conta do banco ainda não vinculada, com a sugestão de vínculo
 * calculada por App\Domain\Banking\Support\PendingProviderAccounts.
 * Recebe um ProviderAccountSuggestion (a conta do provedor mais o id
 * sugerido) em vez de só a ProviderAccount, como ImportBatchResource faz
 * com um model: suggested_account_id não é um dado do provedor.
 *
 * @mixin ProviderAccountSuggestion
 */
final class ProviderAccountResource extends JsonResource
{
    public function __construct(ProviderAccountSuggestion $suggestion)
    {
        parent::__construct($suggestion);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProviderAccountSuggestion $suggestion */
        $suggestion = $this->resource;
        $account = $suggestion->account;

        return [
            'external_id' => $account->id,
            'name' => $account->name,
            'number' => $account->number,
            // Mesmo enum de Account::$type (não a string crua do
            // provedor): o frontend já sabe lidar com AccountType.
            'kind' => AccountMapper::accountTypeFor($account->kind),
            'currency' => $account->currency,
            // Sinal do app, não o do provedor: em cartão, o valor devido já
            // sai negativo aqui, como o saldo calculado da conta vai ficar
            // depois do vínculo — ver AccountMapper::appBalanceCents().
            'balance' => AccountMapper::appBalanceCents($account),
            'suggested_account_id' => $suggestion->suggestedAccountId,
        ];
    }
}
