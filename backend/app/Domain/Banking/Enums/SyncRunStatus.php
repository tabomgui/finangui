<?php

namespace App\Domain\Banking\Enums;

/**
 * Status de uma linha de App\Domain\Banking\Models\BankSyncRun.
 * `Partial` é terminou com avisos (`warnings` não vazio) — não um erro, mas
 * algo que vale o usuário conferir.
 */
enum SyncRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Error = 'error';
}
