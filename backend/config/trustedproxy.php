<?php

return [
    /*
    | IPs/CIDRs confiáveis para os headers X-Forwarded-* (usado pela
    | middleware Illuminate\Http\Middleware\TrustProxies).
    |
    | Essa chave é lida em tempo de requisição, dentro de
    | TrustProxies::setTrustedProxyIpAddresses() (chamado por handle()):
    | $this->proxies() ?: config('trustedproxy.proxies'). Não em bootstrap/app.php:
    | nesse ponto do boot, o closure passado a
    | withMiddleware() já roda (via afterResolving(HttpKernel::class)), mas
    | LoadEnvironmentVariables/LoadConfiguration ainda não — então env() e
    | config() não são confiáveis ali (confirmado lendo o boot do Laravel 13:
    | Application::handleRequest() resolve o kernel, disparando esse closure,
    | antes de $kernel->handle() rodar os bootstrappers). Por isso o valor
    | vem daqui, e bootstrap/app.php só define os headers aceitos (constante,
    | sem depender de env).
    |
    | Default cobre as faixas privadas RFC1918: cobre o proxy TLS (Caddy ou
    | Cloudflare Tunnel) que entra pela mesma rede Docker em produção, sem
    | confiar em '*' (qualquer IP), caso a
    | porta do backend seja exposta por engano.
    */
    'proxies' => env('TRUSTED_PROXIES', '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'),
];
