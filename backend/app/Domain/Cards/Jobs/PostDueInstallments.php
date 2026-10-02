<?php

namespace App\Domain\Cards\Jobs;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Parcela projetada vira lançada quando a data dela chega. Roda por usuário,
 * dentro de UserContext, para o escopo por usuário continuar valendo.
 */
final class PostDueInstallments implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $today = CarbonImmutable::today()->toDateString();
        $due = fn (Builder $query) => $query
            ->where('status', TransactionStatus::Projected->value)
            ->whereNotNull('installment_plan_id')
            ->where('date', '<=', $today);

        $owners = $due(Transaction::query()->withoutGlobalScopes())->select('user_id');

        // eachById: owners encolhe a cada usuário processado (ele deixa de ter
        // parcela vencida); paginação por offset (each/chunk) pularia donos
        // depois do primeiro lote. eachById pagina pelo id do User, que não
        // encolhe.
        User::query()->whereIn('id', $owners)->eachById(
            fn (User $user) => UserContext::run(
                $user,
                fn () => $due(Transaction::query())->update(['status' => TransactionStatus::Posted->value]),
            ),
        );
    }
}
