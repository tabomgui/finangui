<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('semeia o usuário de desenvolvimento fora de produção', function () {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'dev@finangui.test')->exists())->toBeTrue();
});

it('não semeia o usuário de desenvolvimento em produção', function () {
    $this->app['env'] = 'production';

    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

    expect(User::count())->toBe(0);
});
