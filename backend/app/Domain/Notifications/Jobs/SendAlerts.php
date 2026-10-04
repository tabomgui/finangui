<?php

namespace App\Domain\Notifications\Jobs;

use App\Domain\Budgets\Queries\MonthBudget;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Notifications\Notifications\BudgetExceededNotification;
use App\Domain\Notifications\Notifications\OccurrencesOverdueNotification;
use App\Domain\Notifications\Notifications\StatementDueNotification;
use App\Domain\Notifications\Support\NotificationDeduper;
use App\Domain\Recurrences\Queries\OverdueOccurrences;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\DatabaseNotification;
use Throwable;

/**
 * Cria os alertas do dia (fatura vencendo, orçamento estourado, previstos
 * atrasados), por usuário, dentro de UserContext, e apaga notificações lidas
 * com mais de 90 dias. Uma falha isolada num usuário é reportada e não
 * impede os demais de receber seus alertas.
 *
 * ShouldBeUnique: só uma execução por vez (ex.: o agendamento disparando de
 * novo antes da anterior terminar) — reforça a deduplicação por key
 * (App\Domain\Notifications\Support\NotificationDeduper), que já protege
 * mesmo sem isso, mas evita o trabalho duplicado de varrer todo usuário de
 * novo em paralelo. $timeout abaixo de DB_QUEUE_RETRY_AFTER (660s, ver
 * .env.example), mesmo raciocínio de App\Domain\Banking\Jobs\SyncConnection.
 */
final class SendAlerts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function uniqueId(): string
    {
        return self::class;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function handle(MonthBudget $monthBudget, OverdueOccurrences $overdueOccurrences, NotificationDeduper $deduper): void
    {
        $today = CarbonImmutable::today();

        User::query()->eachById(function (User $user) use ($today, $monthBudget, $overdueOccurrences, $deduper) {
            try {
                UserContext::run($user, fn () => $this->runForUser($user, $today, $monthBudget, $overdueOccurrences, $deduper));
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $this->cleanupRead();
    }

    private function runForUser(
        User $user,
        CarbonImmutable $today,
        MonthBudget $monthBudget,
        OverdueOccurrences $overdueOccurrences,
        NotificationDeduper $deduper,
    ): void {
        $this->statementAlerts($user, $today, $deduper);
        $this->budgetAlerts($user, $today, $monthBudget, $deduper);
        $this->occurrenceAlerts($user, $today, $overdueOccurrences, $deduper);
    }

    /**
     * Vencimento em até 3 dias (inclui hoje) e ainda não paga (remaining >
     * 0) — fatura paga não alerta.
     */
    private function statementAlerts(User $user, CarbonImmutable $today, NotificationDeduper $deduper): void
    {
        CardStatement::query()->withTotals()
            ->whereBetween('due_date', [$today->toDateString(), $today->addDays(3)->toDateString()])
            ->with('account')
            ->get()
            ->each(function (CardStatement $statement) use ($user, $today, $deduper) {
                if ($statement->remaining()->cents <= 0) {
                    return;
                }

                $days = (int) $today->diffInDays($statement->due_date);

                $deduper->send($user, new StatementDueNotification(
                    $statement->id,
                    $statement->account_id,
                    $statement->account->name,
                    $days,
                ));
            });
    }

    /**
     * Reaproveita App\Domain\Budgets\Queries\MonthBudget::for(): já exclui
     * categoria de transferência e exceção de valor zero, e já soma filha +
     * pai quando a categoria-pai está orçada.
     */
    private function budgetAlerts(User $user, CarbonImmutable $today, MonthBudget $monthBudget, NotificationDeduper $deduper): void
    {
        foreach ($monthBudget->for($today)->items as $item) {
            if ($item['spent'] <= $item['amount']) {
                continue;
            }

            $deduper->send($user, new BudgetExceededNotification(
                $item['category']['id'],
                $item['category']['name'],
                $today->format('Y-m'),
            ));
        }
    }

    /**
     * Uma notificação agregada por dia, nunca uma por ocorrência —
     * OverdueOccurrences::count() conta sem carregar os registros nem os
     * relacionamentos, que esta notificação nem usa. Antes de criar a de
     * hoje, marca como lida qualquer notificação deste tipo de um dia
     * anterior: o número de ontem já não representa a pendência atual.
     */
    private function occurrenceAlerts(User $user, CarbonImmutable $today, OverdueOccurrences $overdueOccurrences, NotificationDeduper $deduper): void
    {
        $count = $overdueOccurrences->count($today);

        if ($count === 0) {
            return;
        }

        $notification = new OccurrencesOverdueNotification($count, $today->toDateString());

        $user->notifications()
            ->where('type', OccurrencesOverdueNotification::class)
            ->whereNull('read_at')
            ->whereRaw("data::jsonb ->> 'key' != ?", [$notification->dedupeKey()])
            ->update(['read_at' => CarbonImmutable::now()]);

        $deduper->send($user, $notification);
    }

    /**
     * notifications não é BelongsToUser (tabela padrão do Laravel,
     * notifiable polimórfico): a limpeza roda uma vez só, fora do laço por
     * usuário.
     */
    private function cleanupRead(): void
    {
        DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->whereNotNull('read_at')
            ->where('read_at', '<', CarbonImmutable::now()->subDays(90))
            ->delete();
    }
}
