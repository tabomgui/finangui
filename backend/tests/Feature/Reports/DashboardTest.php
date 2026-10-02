<?php

use App\Domain\Accounts\Models\Account;
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

it('isola o resumo de dados de outro usuário', function () {
    $user = actingAsUser();
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
