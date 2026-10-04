<?php

namespace App\Http\Requests\Goals\Concerns;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Models\Account;
use Illuminate\Validation\Validator;

/**
 * account_id de uma meta só aceita conta do próprio usuário (checado em
 * rules() por cada request via Rule::exists), não arquivada, fora de
 * cartão de crédito (saldo de cartão é dívida, não reserva) e na moeda
 * principal (progresso/alvo da meta são sempre nessa moeda).
 */
trait ValidatesGoalAccount
{
    private function validateGoalAccount(Validator $validator): void
    {
        if (! $this->filled('account_id')) {
            return;
        }

        $account = Account::query()->find($this->input('account_id'));

        if ($account === null) {
            return;
        }

        if ($account->is_archived) {
            $validator->errors()->add('account_id', 'Conta arquivada.');

            return;
        }

        if ($account->type === AccountType::CreditCard) {
            $validator->errors()->add('account_id', 'Conta de cartão de crédito não pode ser vinculada a uma meta.');

            return;
        }

        /** @var string $primaryCurrency */
        $primaryCurrency = config('finangui.primary_currency');

        if ($account->currency !== $primaryCurrency) {
            $validator->errors()->add('account_id', 'Só contas na moeda principal podem ser vinculadas a uma meta.');
        }
    }
}
