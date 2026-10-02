<?php

return [
    /*
    | Cadastro público (/api/v1/auth/register e criação de conta pelo Google).
    | Desligado por padrão: o primeiro usuário nasce com `php artisan user:create`.
    */
    'registration_enabled' => (bool) env('REGISTRATION_ENABLED', false),
];
