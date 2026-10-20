<?php

namespace App\Domain\Categorization\Actions;

use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Liga ou desliga, para o usuário, o uso da categoria do banco na
 * sincronização (`users.use_provider_categories`, ver
 * App\Domain\Imports\Actions\IngestTransactions). Desligando, pode também
 * tirar a categoria de todo lançamento do usuário categorizado pelo banco
 * (Transaction::scopeCategorizedByProvider()), que volta a ficar pendente na
 * tela Categorizar — category_id e categorized_by viram null; a descrição (e
 * a description_key) não mudam.
 *
 * Lançamento com categoria de transferência (ela mesma ou pelo pai) nunca
 * é limpo, mesmo vindo do banco: ver Transaction::scopeCategorizedByProvider().
 *
 * Tudo numa única transação, com a linha do usuário travada (FOR UPDATE):
 * serializa com App\Domain\Categorization\Actions\ApplyCategorization, que
 * trava a mesma linha, e com IngestTransactions, que lê a configuração com
 * FOR KEY SHARE nessa linha e a segura até o fim do lote (FOR UPDATE é a
 * única trava que conflita com ela). Um lote já em andamento termina antes
 * (com a configuração que leu) e a limpeza vê o que ele gravou; um lote que
 * começa depois já lê o valor novo.
 */
final class UpdateProviderCategoriesSetting
{
    /**
     * @return int quantos lançamentos perderam a categoria do banco (0 quando não houve limpeza)
     */
    public function handle(User $user, bool $enabled, bool $clearProviderCategories = false): int
    {
        return DB::transaction(function () use ($user, $enabled, $clearProviderCategories): int {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $user->use_provider_categories = $enabled;
            $user->save();

            if ($enabled || ! $clearProviderCategories) {
                return 0;
            }

            return Transaction::query()
                ->where('transactions.user_id', $user->id)
                ->categorizedByProvider()
                ->update(['category_id' => null, 'categorized_by' => null]);
        });
    }
}
