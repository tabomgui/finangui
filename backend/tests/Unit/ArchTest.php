<?php

use App\Models\Concerns\BelongsToUser;

arch('sem helpers de debug')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();

arch('controllers não acessam o banco direto pelo facade DB')
    ->expect('App\Http\Controllers')
    ->not->toUse('Illuminate\Support\Facades\DB');

arch('domínio não depende da camada HTTP')
    ->expect('App\Domain')
    ->not->toUse('App\Http');

it('todo model de domínio usa BelongsToUser', function () {
    $files = glob(app_path('Domain/*/Models/*.php'));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        $class = 'App\\Domain\\'.basename(dirname($file, 2)).'\\Models\\'.basename($file, '.php');

        expect(in_array(BelongsToUser::class, class_uses_recursive($class), true))
            ->toBeTrue("{$class} precisa usar BelongsToUser");
    }
});

it('todo job de domínio roda dentro de UserContext', function () {
    $files = glob(app_path('Domain/*/Jobs/*.php'));

    expect($files)->toBeArray();

    foreach ($files as $file) {
        expect(file_get_contents($file))->toContain('UserContext::run(', basename($file).' precisa usar UserContext::run()');
    }
});
