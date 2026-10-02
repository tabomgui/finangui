<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Errors\AccountTypeLocked;
use App\Domain\Accounts\Models\Account;

final class UpdateAccount
{
    private const CARD_FIELDS = ['credit_limit', 'closing_day', 'due_day', 'last_four'];

    /**
     * @param  array<string, mixed>  $input  dados já validados (parciais)
     *
     * @throws AccountTypeLocked
     */
    public function handle(Account $account, array $input): Account
    {
        if (array_key_exists('type', $input)) {
            $newType = AccountType::from($input['type']);
            $cardChange = ($newType === AccountType::CreditCard) !== $account->isCreditCard();

            // Faturas e parcelas dependem do tipo: trocar com histórico deixaria
            // lançamentos de cartão numa conta comum (ou o contrário).
            if ($cardChange && $account->transactions()->exists()) {
                throw new AccountTypeLocked;
            }

            if ($newType !== AccountType::CreditCard) {
                foreach (self::CARD_FIELDS as $field) {
                    $input[$field] = null;
                }
            }
        }

        $account->update($input);

        return $account;
    }
}
