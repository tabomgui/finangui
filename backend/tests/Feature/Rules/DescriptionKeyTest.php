<?php

use App\Domain\Transactions\Models\Transaction;

it('preenche a chave da descrição ao criar e ao editar a descrição', function () {
    actingAsUser();
    $transaction = Transaction::factory()->create(['description' => 'Uber *Trip 123']);

    expect($transaction->fresh()->description_key)->toBe('UBER TRIP');

    $transaction->update(['description' => 'Padaria São João']);
    expect($transaction->fresh()->description_key)->toBe('PADARIA SAO JOAO');
});
