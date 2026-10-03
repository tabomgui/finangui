<?php

namespace App\Domain\Rules\Actions;

use App\Domain\Rules\Models\Rule;
use Illuminate\Support\Facades\DB;

final class ReorderRules
{
    /**
     * A ordem toda vem validada pelo ReorderRulesRequest (mesmo conjunto de
     * ids do usuário, sem repetir); aqui só grava priority = posição na
     * lista. Trava as linhas pela duração da transação: duas chamadas
     * concorrentes não podem deixar prioridades repetidas ou com buraco.
     *
     * @param  list<int>  $ids
     */
    public function handle(array $ids): void
    {
        // @phpstan-ignore arrayValues.list (defesa: list<int> vem do docblock, não é garantia real de quem chama)
        $ids = array_values($ids);

        DB::transaction(function () use ($ids): void {
            $rules = Rule::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');

            foreach ($ids as $priority => $id) {
                $rules->get($id)?->update(['priority' => $priority]);
            }
        });
    }
}
