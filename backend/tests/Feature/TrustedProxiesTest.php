<?php

it('confia no X-Forwarded-Proto de um proxy na rede confiável', function () {
    $this->call('GET', '/up', server: [
        'REMOTE_ADDR' => '172.18.0.5',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->assertOk();

    expect(request()->isSecure())->toBeTrue();
});

it('ignora X-Forwarded-Proto de uma origem fora da rede confiável', function () {
    $this->call('GET', '/up', server: [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->assertOk();

    expect(request()->isSecure())->toBeFalse();
});
