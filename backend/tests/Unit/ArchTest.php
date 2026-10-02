<?php

arch('sem helpers de debug')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('controllers não acessam o banco direto pelo facade DB')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('domínio não depende da camada HTTP')
    ->expect('App\Domain')
    ->not->toUse('App\Http');
