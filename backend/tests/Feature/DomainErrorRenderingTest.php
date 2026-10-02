<?php

use App\Domain\Shared\DomainError;
use Illuminate\Support\Facades\Route;

it('renderiza erro de domínio como 409 com código legível por máquina', function () {
    Route::get('/api/_test/domain-error', function () {
        throw new class('Algo não pode.') extends DomainError
        {
            public function errorCode(): string
            {
                return 'something_forbidden';
            }
        };
    });

    $this->getJson('/api/_test/domain-error')
        ->assertStatus(409)
        ->assertExactJson(['code' => 'something_forbidden', 'message' => 'Algo não pode.']);
});
