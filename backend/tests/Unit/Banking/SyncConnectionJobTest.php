<?php

use App\Domain\Banking\Jobs\SyncConnection;

it('tem tries, timeout e backoff para o retry com espera crescente', function () {
    $job = new SyncConnection(42);

    // $timeout < DB_QUEUE_RETRY_AFTER: maior que isso, o worker da fila
    // "database" libera o job de novo (acha que travou) antes do handle()
    // atual sequer terminar, rodando dois ao mesmo tempo. O ambiente local
    // não define DB_QUEUE_RETRY_AFTER (cai no default de 90s do próprio
    // config/queue.php) — a comparação é contra o valor documentado em
    // .env.example, o que de fato importa ser maior que $timeout em
    // produção.
    $envExample = (string) file_get_contents(base_path('.env.example'));
    preg_match('/^DB_QUEUE_RETRY_AFTER=(\d+)$/m', $envExample, $matches);
    $retryAfter = (int) $matches[1];

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(600)
        ->and($job->timeout)->toBeLessThan($retryAfter)
        ->and($job->backoff)->toBe([60, 300, 900])
        ->and($job->uniqueFor())->toBe(1800)
        ->and($job->uniqueId())->toBe('42');
});
