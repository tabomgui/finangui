<?php

namespace App\Domain\Banking\Jobs;

use App\Models\User;
use App\Support\UserContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sincroniza uma conexão bancária (item, contas, saldos, faturas,
 * transações, limpeza de pendentes antigos). Único por conexão: duas
 * sincronizações da mesma conexão em paralelo não fazem sentido —
 * App\Domain\Banking\Actions\QueueConnectionSync confere o lock antes de
 * despachar (mesmo padrão de App\Domain\Rules\Actions\QueueRuleApplication),
 * para quem chama duas vezes em seguida receber 409 em vez de um 202 que
 * não enfileirou nada de verdade.
 *
 * Este job ainda é um esqueleto: os passos do sync ainda não existem. Por
 * ora só garante o contrato (construtor final, unicidade, execução dentro
 * de UserContext) para quem despacha/testa o dispatch.
 */
final class SyncConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $connectionId,
        public int $userId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        UserContext::run($user, function (): void {
            // Os passos de sincronização (atualizar o item, contas, faturas,
            // transações e a limpeza de pendentes antigos) chegam depois.
        });
    }
}
