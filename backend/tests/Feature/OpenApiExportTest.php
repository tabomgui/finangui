<?php

it('exporta documento OpenAPI cobrindo a API v1', function () {
    $path = storage_path('framework/testing/openapi.json');

    $this->artisan('scramble:export', ['--path' => $path])->assertSuccessful();

    $document = json_decode((string) file_get_contents($path), true);

    expect($document['paths'])->toHaveKeys([
        '/accounts', '/categories', '/tags', '/transactions', '/transfers', '/dashboard', '/me', '/auth/login',
    ]);
});
