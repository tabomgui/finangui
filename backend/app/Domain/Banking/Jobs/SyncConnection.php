<?php

namespace App\Domain\Banking\Jobs;

use App\Domain\Banking\Models\BankConnection;
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
 * $timeout fica abaixo de DB_QUEUE_RETRY_AFTER (660s, ver .env.example):
 * maior que isso, o worker da fila "database" acha que o job travou e o
 * libera de novo antes do handle() atual terminar, rodando os dois ao
 * mesmo tempo. uniqueFor() (30 min) é bem maior que o pior caso de
 * $timeout × $tries, para o lock não vencer sozinho enquanto uma tentativa
 * de retry ainda está de pé.
 *
 * Este job ainda é um esqueleto: os passos do sync ainda não existem. Por
 * ora só garante o contrato (unicidade, retry, execução dentro de
 * UserContext) para quem despacha/testa o dispatch.
 */
final class SyncConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public int $connectionId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function uniqueFor(): int
    {
        return 1800;
    }

    public function handle(): void
    {
        // withoutGlobalScopes: não há usuário autenticado ainda (é
        // justamente o que este find serve para descobrir).
        $connection = BankConnection::query()->withoutGlobalScopes()->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        $user = User::query()->find($connection->user_id);

        if ($user === null) {
            return;
        }

        UserContext::run($user, function (): void {
            // Os passos de sincronização (atualizar o item, contas, faturas,
            // transações e a limpeza de pendentes antigos) chegam depois.
        });
    }
}
