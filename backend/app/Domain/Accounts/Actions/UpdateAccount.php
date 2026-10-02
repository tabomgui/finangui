<?php

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Enums\AccountType;
use App\Domain\Accounts\Errors\AccountTypeLocked;
use App\Domain\Accounts\Models\Account;
use Illuminate\Support\Facades\DB;

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
        return DB::transaction(function () use ($account, $input) {
            // Trava a linha pela duração do check+update: sem isso, uma transação
            // criada entre o "existe lançamento?" e o update poderia deixar a conta
            // com histórico de um tipo e cartão/comum do outro.
            $account = $account->newQuery()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $wasCard = $account->isCreditCard();

            if (array_key_exists('type', $input)) {
                $newType = $input['type'] instanceof AccountType ? $input['type'] : AccountType::from($input['type']);
                $cardChange = ($newType === AccountType::CreditCard) !== $wasCard;

                // Faturas e parcelas dependem do tipo: trocar com histórico deixaria
                // lançamentos de cartão numa conta comum (ou o contrário).
                if ($cardChange && $account->transactions()->exists()) {
                    throw new AccountTypeLocked;
                }
            } else {
                $newType = $account->type;
            }

            if ($newType !== AccountType::CreditCard) {
                foreach (self::CARD_FIELDS as $field) {
                    $input[$field] = null;
                }

                // A troca só é permitida sem lançamentos: qualquer fatura do
                // cartão está necessariamente vazia e vai junto.
                if ($wasCard) {
                    $account->statements()->delete();
                }
            }

            $account->update($input);

            return $account;
        });
    }
}
