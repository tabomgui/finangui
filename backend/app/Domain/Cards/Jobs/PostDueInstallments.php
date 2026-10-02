<?php

namespace App\Domain\Cards\Jobs;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
        $due = fn () => Transaction::query()
            ->where('status', TransactionStatus::Projected->value)
            ->whereNotNull('installment_plan_id')
            ->where('date', '<=', $today);

        $owners = Transaction::query()->withoutGlobalScopes()
            ->where('status', TransactionStatus::Projected->value)
            ->whereNotNull('installment_plan_id')
            ->where('date', '<=', $today)
            ->select('user_id');

        User::query()->whereIn('id', $owners)->each(
            fn (User $user) => UserContext::run($user, fn () => $due()->update(['status' => TransactionStatus::Posted->value])),
        );
    }
}
