<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Recurrences\Models\Recurrence;
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

it('agrupa as maiores despesas pela categoria raiz', function () {
    actingAsUser();
    $food = Category::factory()->create(['name' => 'Alimentação']);
    $market = Category::factory()->create(['name' => 'Mercado', 'parent_id' => $food->id]);
    $transport = Category::factory()->create(['name' => 'Transporte']);

    Transaction::factory()->create(['date' => '2026-10-02', 'amount' => 10000, 'category_id' => $food->id]);
    Transaction::factory()->create(['date' => '2026-10-03', 'amount' => 25000, 'category_id' => $market->id]);
    Transaction::factory()->create(['date' => '2026-10-04', 'amount' => 5000, 'category_id' => $transport->id]);
    Transaction::factory()->create(['date' => '2026-10-05', 'amount' => 3000, 'category_id' => null]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.top_categories.0.category_id', $food->id)
        ->assertJsonPath('data.top_categories.0.name', 'Alimentação')
        ->assertJsonPath('data.top_categories.0.amount', 35000)
        ->assertJsonPath('data.top_categories.1.name', 'Transporte')
        ->assertJsonPath('data.top_categories.2.category_id', null)
        ->assertJsonPath('data.top_categories.2.name', 'Sem categoria');
});

it('exclui transações de subcategoria cujo pai é marcado como transferência', function () {
    actingAsUser();
    $parent = Category::factory()->transfer()->create(['name' => 'Investimentos']);
    $child = Category::factory()->create(['name' => 'Tesouro', 'parent_id' => $parent->id, 'is_transfer' => false]);

    Transaction::factory()->create(['date' => '2026-10-05', 'amount' => 10000, 'category_id' => $child->id]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.expense', 0)
        ->assertJsonCount(0, 'data.top_categories');
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

it('calcula o saldo das contas até o fim do mês consultado quando o mês já passou', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    $account = Account::factory()->create(['opening_balance' => 1000]);
    Transaction::factory()->for($account)->income()->create(['date' => '2026-09-10', 'amount' => 500]);
    Transaction::factory()->for($account)->create(['date' => '2026-10-10', 'amount' => 200]);

    $this->getJson('/api/v1/dashboard?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data.balance_date', '2026-09-30')
        ->assertJsonPath('data.total_balance', 1500)
        ->assertJsonPath('data.accounts.0.balance', 1500);
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

it('conta o saldo do cartão no saldo total, mesmo com saldo inicial negativo', function () {
    actingAsUser();
    Account::factory()->create(['name' => 'A', 'opening_balance' => 1000]);
    Account::factory()->creditCard()->create(['name' => 'Cartão', 'opening_balance' => -2000]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.total_balance', -1000)
        ->assertJsonCount(2, 'data.accounts');
});

it('parcela projetada do mês não entra em despesa/maiores categorias; a lançada conta na própria data', function () {
    actingAsUser();
    $this->travelTo('2026-10-06');
    $category = Category::factory()->create(['name' => 'Eletrônicos']);
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();

    $this->postJson('/api/v1/transactions', [
        'account_id' => $card->id, 'date' => '2026-10-05', 'amount' => 30000, 'direction' => 'out',
        'description' => 'Notebook', 'installments' => 2, 'category_id' => $category->id,
    ])->assertCreated();
    // Parcela 1 (2026-10-05) já lançada (posted); parcela 2 (2026-11-05) é projetada.

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertJsonPath('data.expense', 15000)
        ->assertJsonPath('data.top_categories.0.amount', 15000);

    $this->getJson('/api/v1/dashboard?month=2026-11')
        ->assertJsonPath('data.expense', 0)
        ->assertJsonCount(0, 'data.top_categories');
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

it('soma saldo de hoje com previstas e pendentes até o fim do mês atual, ignorando ignoradas e outra moeda', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    $account = Account::factory()->create(['opening_balance' => 1000]);
    $usd = Account::factory()->create(['currency' => 'USD', 'opening_balance' => 5000]);

    Transaction::factory()->for($account)->create(['date' => '2026-10-05', 'amount' => 200]); // posted, conta pro saldo de hoje
    Transaction::factory()->for($account)->create(['date' => '2026-10-20', 'amount' => 300, 'status' => TransactionStatus::Projected]);
    Transaction::factory()->for($account)->income()->create(['date' => '2026-10-18', 'amount' => 150, 'status' => TransactionStatus::Pending]);
    Transaction::factory()->for($account)->create([
        'date' => '2026-10-22', 'amount' => 99999, 'status' => TransactionStatus::Projected, 'is_ignored' => true,
    ]);
    Transaction::factory()->for($usd)->create([
        'date' => '2026-10-22', 'amount' => 99999, 'currency' => 'USD', 'status' => TransactionStatus::Projected,
    ]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.projected_balance', 650); // 1000 - 200 (hoje) - 300 + 150
});

it('saldo previsto de mês futuro acumula previstas de meses anteriores ainda pendentes', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    $account = Account::factory()->create(['opening_balance' => 1000]);

    Transaction::factory()->for($account)->create(['date' => '2026-10-20', 'amount' => 100, 'status' => TransactionStatus::Projected]);
    Transaction::factory()->for($account)->create(['date' => '2026-11-10', 'amount' => 500, 'status' => TransactionStatus::Projected]);

    $this->getJson('/api/v1/dashboard?month=2026-11')
        ->assertOk()
        ->assertJsonPath('data.projected_balance', 400); // 1000 - 100 - 500
});

it('não traz saldo previsto para mês passado', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    Account::factory()->create(['opening_balance' => 1000]);

    $this->getJson('/api/v1/dashboard?month=2026-09')
        ->assertOk()
        ->assertJsonMissingPath('data.projected_balance');
});

it('não traz saldo previsto para dois meses ou mais no futuro', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    Account::factory()->create(['opening_balance' => 1000]);

    $this->getJson('/api/v1/dashboard?month=2026-12')
        ->assertOk()
        ->assertJsonMissingPath('data.projected_balance');
});

it('conta no saldo previsto uma transação já lançada com data futura dentro do mês atual', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    $account = Account::factory()->create(['opening_balance' => 1000]);
    // Lançada (posted), mas com data depois de hoje: não entra no saldo "de
    // hoje" (total_balance), mas precisa entrar na base do saldo previsto,
    // que é até o fim do mês, não até hoje.
    Transaction::factory()->for($account)->create(['date' => '2026-10-20', 'amount' => 200]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.total_balance', 1000)
        ->assertJsonPath('data.projected_balance', 800);
});

it('soma no saldo previsto tanto uma prevista de recorrência quanto uma parcela projetada de cartão', function () {
    actingAsUser();
    $this->travelTo('2026-09-05');
    $account = Account::factory()->create(['opening_balance' => 100000]);
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create();

    $this->postJson('/api/v1/transactions', [
        'account_id' => $card->id, 'date' => '2026-09-05', 'amount' => 20000, 'direction' => 'out',
        'description' => 'Notebook', 'installments' => 2,
    ])->assertCreated();
    // Parcela 1 (09-05, 10000) já lançada; parcela 2 (10-05, 10000) é projetada.

    $recurrence = Recurrence::factory()->create([
        'account_id' => $account->id, 'description' => 'Aluguel', 'amount' => 5000, 'direction' => 'out',
    ]);
    Transaction::factory()->for($account)->create([
        'status' => 'projected', 'source' => 'recurrence', 'recurrence_id' => $recurrence->id,
        'recurrence_date' => '2026-10-20', 'date' => '2026-10-20', 'amount' => 5000, 'direction' => 'out',
    ]);

    $this->travelTo('2026-10-15');

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertOk()
        // saldo até fim de outubro (100000 - 10000 da parcela 1 já lançada)
        // - 10000 (parcela 2 projetada) - 5000 (prevista de recorrência)
        ->assertJsonPath('data.projected_balance', 75000);
});

it('exclui previstas de conta arquivada do saldo previsto', function () {
    actingAsUser();
    $this->travelTo('2026-10-15');
    Account::factory()->create(['opening_balance' => 1000]);
    $archived = Account::factory()->archived()->create(['opening_balance' => 0]);
    Transaction::factory()->for($archived)->create(['date' => '2026-10-20', 'amount' => 500, 'status' => TransactionStatus::Projected]);

    $this->getJson('/api/v1/dashboard?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.projected_balance', 1000);
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
        ->assertJsonPath('data.expense', 20000)
        ->assertJsonCount(1, 'data.top_categories')
        ->assertJsonPath('data.top_categories.0.name', 'Categoria do usuário');
});
