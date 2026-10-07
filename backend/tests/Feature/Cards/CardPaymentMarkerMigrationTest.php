<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Str;

/**
 * Transações salvas antes de card_payment_statement_id existir: uma perna de
 * transferência para dentro do cartão já era, por definição, um pagamento —
 * o backfill da migration (database/migrations/2026_10_17_000100_*) precisa
 * marcá-las sem esperar por uma reconciliação. O guard por hasColumn deixa
 * rodar a migration de novo (coluna já existe, só o backfill se repete),
 * então o teste chama up() diretamente em vez de depender de migrate:fresh.
 */
it('preenche card_payment_statement_id de pernas de transferência já gravadas num cartão', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id]);

    $transferLeg = Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => 'in',
        'transfer_id' => (string) Str::uuid(), 'statement_id' => $statement->id,
    ]);
    DB::table('transactions')->where('id', $transferLeg->id)->update(['card_payment_statement_id' => null]);

    $plainCharge = Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => 'out', 'statement_id' => $statement->id,
    ]);

    $outsideCard = Account::factory()->create(['user_id' => $user->id]);
    $outsideTransferLeg = Transaction::factory()->create([
        'account_id' => $outsideCard->id, 'direction' => 'in', 'transfer_id' => (string) Str::uuid(),
    ]);

    migrationInstance('2026_10_17_000100_add_card_payment_columns_to_transactions_table')->up();

    expect($transferLeg->refresh()->card_payment_statement_id)->toBe($statement->id)
        ->and($plainCharge->refresh()->card_payment_statement_id)->toBeNull()
        ->and($outsideTransferLeg->refresh()->card_payment_statement_id)->toBeNull();
});

it('o backfill é idempotente: rodar a migration de novo não muda nada', function () {
    $user = actingAsUser();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id]);
    $transferLeg = Transaction::factory()->create([
        'account_id' => $card->id, 'direction' => 'in',
        'transfer_id' => (string) Str::uuid(), 'statement_id' => $statement->id,
    ]);

    migrationInstance('2026_10_17_000100_add_card_payment_columns_to_transactions_table')->up();
    migrationInstance('2026_10_17_000100_add_card_payment_columns_to_transactions_table')->up();

    expect($transferLeg->refresh()->card_payment_statement_id)->toBe($statement->id);
});
