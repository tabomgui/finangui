<?php

return [
    /*
    | Cadastro público (/api/v1/auth/register e criação de conta pelo Google).
    | Desligado por padrão: o primeiro usuário nasce com `php artisan user:create`.
    */
    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', false),

    /*
    | Moeda usada nos totais do dashboard (saldo total, receita/despesa, maiores
    | categorias). Contas e transações em outras moedas aparecem nas listagens,
    | mas ficam fora desses agregados até existir conversão (fora do escopo agora).
    */
    'primary_currency' => env('PRIMARY_CURRENCY', 'BRL'),
];
