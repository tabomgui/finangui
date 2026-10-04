<?php

namespace App\Domain\Recurrences\Jobs;

use App\Domain\Recurrences\Actions\GenerateOccurrences;
use App\Domain\Recurrences\Models\Recurrence;
use App\Models\User;
use App\Support\UserContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Gera as ocorrências previstas de todas as recorrências ativas, por
 * usuário, dentro de UserContext, para o escopo por usuário continuar
 * valendo.
 */
final class GenerateRecurrences implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $owners = Recurrence::query()->withoutGlobalScopes()
            ->where('is_active', true)
            ->select('user_id');

        User::query()->whereIn('id', $owners)->eachById(
            fn (User $user) => UserContext::run($user, function () {
                $generateOccurrences = app(GenerateOccurrences::class);

                Recurrence::query()->where('is_active', true)->get()
                    ->each(fn (Recurrence $recurrence) => $generateOccurrences->handle($recurrence));
            }),
        );
    }
}
