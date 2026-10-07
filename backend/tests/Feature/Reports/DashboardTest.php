<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Banking\Models\BankConnection;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;

it('soma receitas e despesas do mês ignorando transferências, ignoradas e projetadas', function () {
    actingAsUser();
    $account = Account::factory()->create();
    $other = Account::factory()->create();
    $transferCategory = Category::factory()->transfer()->create();

    Transaction::factory()->for($account)->income()->create(['date' => '2026-10-05', 'amount' => 800000]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-06', 'amount' => 12000]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-07', 'amount' => 5000, 'is_ignored' => true]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-08', 'amount' => 7000, 'status' => TransactionStatus::Projected]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-09', 'amount' => 30000, 'category_id' => $transferCategory->id]);
    Transaction::factory()->for($account)->create(['date' => '2026-09-30', 'amount' => 99999]);

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $account->id, 'to_account_id' => $other->id,
        'date' => '2026-10-10', 'amount' => 40000, 'description' => 'Reserva',
    ])->assertCreated();

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-10')
        ->assertJsonPath('data.income', 800000)
        ->assertJsonPath('data.expense', 12000)
        ->assertJsonPath('data.net', 788000);
});

it('exclui transações de subcategoria cujo pai é marcado como transferência', function () {
    actingAsUser();
    $parent = Category::factory()->transfer()->create(['name' => 'Investimentos']);
    $child = Category::factory()->create(['name' => 'Tesouro', 'parent_id' => $parent->id, 'is_transfer' => false]);

    Transaction::factory()->create(['date' => '2026-10-05', 'amount' => 10000, 'category_id' => $child->id]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.expense', 0);
});

it('passa a incluir/excluir a subcategoria quando o pai liga ou desliga a transferência', function () {
    actingAsUser();
    $parent = Category::factory()->create(['name' => 'Investimentos', 'is_transfer' => false]);
    $child = Category::factory()->create(['name' => 'Tesouro', 'parent_id' => $parent->id]);
    Transaction::factory()->create(['date' => '2026-10-05', 'amount' => 10000, 'category_id' => $child->id]);

    $this->getJson('/api/v1/dashboard?month=2026-10')->assertJsonPath('data.expense', 10000);

    $parent->update(['is_transfer' => true]);

    $this->getJson('/api/v1/dashboard?month=2026-10')->assertJsonPath('data.expense', 0);

    $parent->update(['is_transfer' => false]);

    $this->getJson('/api/v1/dashboard?month=2026-10')->assertJsonPath('data.expense', 10000);
});

it('mostra saldo total e por conta sem contas arquivadas', function () {
    actingAsUser();
    Account::factory()->create(['name' => 'A', 'opening_balance' => 1000]);
    Account::factory()->create(['name' => 'B', 'opening_balance' => 2500]);
    Account::factory()->archived()->create(['opening_balance' => 99999]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.total_balance', 3500)
        ->assertJsonCount(2, 'data.accounts')
        ->assertJsonPath('data.accounts.0.balance', 1000);
});

it('usa o mês atual quando não informado e valida o formato', function () {
    actingAsUser();

    $this->getJson('/api/v1/dashboard')->assertJsonPath('data.month', now()->format('Y-m'));
    $this->getJson('/api/v1/dashboard?month=10-2026')->assertStatus(422)->assertJsonValidationErrors('month');
});

it('restringe os totais à moeda principal mas lista todas as contas', function () {
    actingAsUser();
    Account::factory()->create(['name' => 'A Conta BRL', 'opening_balance' => 1000]);
    $usd = Account::factory()->create(['name' => 'B Conta USD', 'currency' => 'USD', 'opening_balance' => 5000]);
    Transaction::factory()->for($usd)->income()->create(['date' => '2026-10-05', 'amount' => 30000, 'currency' => 'USD']);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.currency', 'BRL')
        ->assertJsonPath('data.total_balance', 1000)
        ->assertJsonPath('data.income', 0)
        ->assertJsonCount(2, 'data.accounts')
        ->assertJsonPath('data.accounts.1.currency', 'USD');
});

it('calcula o saldo das contas até hoje mesmo quando o mês consultado já passou (mês e dia são independentes)', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    $account = Account::factory()->create(['opening_balance' => 1000]);
    Transaction::factory()->for($account)->income()->create(['date' => '2026-09-10', 'amount' => 500]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-10', 'amount' => 200]);

    $this->getJson('/api/v1/dashboard?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data.balance_date', '2026-10-15')
        ->assertJsonPath('data.today', '2026-10-15')
        ->assertJsonPath('data.total_balance', 1300)
        ->assertJsonPath('data.accounts.0.balance', 1300);
});

it('calcula o saldo das contas até hoje quando o mês consultado é o mês atual', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    $account = Account::factory()->create(['opening_balance' => 1000]);
    Transaction::factory()->for($account)->income()->create(['date' => '2026-09-10', 'amount' => 500]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-10', 'amount' => 200]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.balance_date', '2026-10-15')
        ->assertJsonPath('data.total_balance', 1300)
        ->assertJsonPath('data.accounts.0.balance', 1300);
});

it('não conta no saldo transação futura dentro do próprio mês atual, mas conta na receita/despesa do mês', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    $account = Account::factory()->create(['opening_balance' => 1000]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-20', 'amount' => 200]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.balance_date', '2026-10-15')
        ->assertJsonPath('data.total_balance', 1000)
        ->assertJsonPath('data.accounts.0.balance', 1000)
        ->assertJsonPath('data.expense', 200);
});

it('exclui o saldo do cartão do saldo total e da lista de contas da Início', function () {
    actingAsUser();
    Account::factory()->create(['name' => 'A', 'opening_balance' => 1000]);
    Account::factory()->creditCard()->create(['name' => 'Cartão', 'opening_balance' => -2000]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.total_balance', 1000)
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonPath('data.accounts.0.name', 'A');
});

it('parcela projetada do mês não entra em despesa; a lançada conta na própria data', function () {
    actingAsUser();
    $this->travelTo('2026-10-06');
    $category = Category::factory()->create(['name' => 'Eletrônicos']);
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();

    $this->postJson('/api/v1/transactions', [
        'account_id' => $card->id, 'date' => '2026-10-05', 'amount' => 30000, 'direction' => 'out',
        'description' => 'Notebook', 'installments' => 2, 'category_id' => $category->id,
    ])->assertCreated();
    // Parcela 1 (2026-10-05) já lançada (posted); parcela 2 (2026-11-05) é projetada.

    $this->getJson('/api/v1/dashboard?month=2026-10')->assertJsonPath('data.expense', 15000);

    $this->getJson('/api/v1/dashboard?month=2026-11')->assertJsonPath('data.expense', 0);
});

it('pagar fatura não muda receita nem despesa (é transferência, não lançamento)', function () {
    actingAsUser();
    $this->travelTo('2026-10-06');
    $checking = Account::factory()->create(['opening_balance' => 100000]);
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();
    $this->postJson('/api/v1/transactions', [
        'account_id' => $card->id, 'date' => '2026-10-05', 'amount' => 30000, 'direction' => 'out', 'description' => 'Compra',
    ])->assertCreated();

    $statement = $this->getJson("/api/v1/cards/{$card->id}")->json('data.current_statement');

    $this->postJson("/api/v1/card-statements/{$statement['id']}/payments", [
        'from_account_id' => $checking->id, 'amount' => 30000, 'date' => '2026-10-06',
    ])->assertCreated();

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.income', 0)
        ->assertJsonPath('data.expense', 30000);
});

it('usa hoje como padrão do dia do saldo no mês futuro, já que não existe saldo real futuro', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    Account::factory()->create(['opening_balance' => 1000]);

    $this->getJson('/api/v1/dashboard?month=2026-12')
        ->assertOk()
        ->assertJsonPath('data.balance_date', '2026-10-15');
});

it('devolve hoje na chave today, igual ao padrão do dia do saldo e independente de date', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    Account::factory()->create(['opening_balance' => 1000]);

    $this->getJson('/api/v1/dashboard?month=2026-10')->assertOk()->assertJsonPath('data.today', '2026-10-15');

    $this->getJson('/api/v1/dashboard?month=2026-01&date=2026-01-05')
        ->assertOk()
        ->assertJsonPath('data.today', '2026-10-15')
        ->assertJsonPath('data.balance_date', '2026-01-05');
});

it('aceita date independente de month e calcula o saldo naquele dia', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    $account = Account::factory()->create(['opening_balance' => 1000]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-03', 'amount' => 200]);

    $this->getJson('/api/v1/dashboard?month=2026-01&date=2026-10-05')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-01')
        ->assertJsonPath('data.balance_date', '2026-10-05')
        ->assertJsonPath('data.total_balance', 800);
});

it('rejeita date com formato inválido', function () {
    actingAsUser();

    $this->getJson('/api/v1/dashboard?date=15-10-2026')
        ->assertStatus(422)
        ->assertJsonValidationErrors('date');
});

it('rejeita date no futuro', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');

    $this->getJson('/api/v1/dashboard?date=2026-10-16')
        ->assertStatus(422)
        ->assertJsonValidationErrors('date');
});

it('isola o saldo conectado e a data entre usuários', function () {
    $user = actingAsUser();
    $this->travelTo('2026-10-15');
    $connection = BankConnection::factory()->active()->create();
    Account::factory()->create([
        'opening_balance' => 0, 'connection_id' => $connection->id,
        'provider_balance' => 5000, 'provider_synced_at' => now(),
    ]);

    actingAsUser();
    $otherConnection = BankConnection::factory()->active()->create();
    Account::factory()->create([
        'opening_balance' => 0, 'connection_id' => $otherConnection->id,
        'provider_balance' => 999999, 'provider_synced_at' => now(),
    ]);

    $this->actingAs($user);

    $this->getJson('/api/v1/dashboard?month=2026-10&date=2026-10-10')
        ->assertOk()
        ->assertJsonPath('data.total_balance', 5000);
});

it('isola o resumo de dados de outro usuário', function () {
    $user = actingAsUser();
    $this->travelTo('2026-10-15');
    $account = Account::factory()->create(['opening_balance' => 1000]);
    $category = Category::factory()->create(['name' => 'Categoria do usuário']);
    Transaction::factory()->for($account)->income()->create(['date' => '2026-10-05', 'amount' => 50000]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-06', 'amount' => 20000, 'category_id' => $category->id]);

    actingAsUser();
    $otherAccount = Account::factory()->create(['opening_balance' => 999999]);
    $otherCategory = Category::factory()->create(['name' => 'Categoria de outro usuário']);
    Transaction::factory()->for($otherAccount)->income()->create(['date' => '2026-10-10', 'amount' => 777777]);
    Transaction::factory()->for($otherAccount)->create(['date' => '2026-10-11', 'amount' => 888888, 'category_id' => $otherCategory->id]);

    $this->actingAs($user);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.total_balance', 31000) // opening_balance 1000 + receita 50000 - despesa 20000
        ->assertJsonCount(1, 'data.accounts')
        ->assertJsonPath('data.income', 50000)
        ->assertJsonPath('data.expense', 20000);
});
