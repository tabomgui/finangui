<?php

use App\Domain\Banking\Errors\ProviderUnavailable;
use App\Domain\Shared\DomainError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Produção roda atrás de um proxy que termina TLS (Caddy, Cloudflare
        // Tunnel) na mesma rede Docker. Sem isto, request()->isSecure() e as
        // URLs geradas por url()/route() ficam http mesmo em produção, porque
        // a conexão entre o proxy e este container é HTTP puro.
        //
        // Só os headers abaixo (sem X-Forwarded-Prefix/AWS-ELB, que não se
        // aplicam aqui). A lista de proxies confiáveis não é passada aqui de
        // propósito: env()/config() ainda não funcionam neste closure (ver
        // config/trustedproxy.php), então ela vem de lá, lida em tempo de
        // requisição pela própria TrustProxies.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(DomainError::class);
        $exceptions->render(fn (DomainError $e) => response()->json([
            'code' => $e->errorCode(),
            'message' => $e->getMessage(),
        ], 409));

        // ProviderUnavailable não é um DomainError (é uma falha do lado de
        // fora, não uma regra de negócio) — só as rotas interativas que
        // falam com o provedor na hora (connect-token, criar, vincular,
        // reconectar, sincronizar) chegam a deixar isso escapar até aqui;
        // App\Domain\Banking\Jobs\SyncConnection, em fila, nunca passa por
        // este renderer (o ciclo de vida de exceção de um job é outro —
        // ver failed()/$tries/$backoff).
        $exceptions->dontReport(ProviderUnavailable::class);
        $exceptions->render(fn (ProviderUnavailable $e) => response()->json([
            'code' => 'provider_unavailable',
            'message' => 'O banco não respondeu. Tente de novo em instantes.',
        ], 503));
    })->create();
