<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Budgets\Models\Budget;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Models\Transaction;

beforeEach(function () {
    actingAsUser();
    $this->account = Account::factory()->create();
});

it('usa o valor padrão quando não há exceção para o mês', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 20000, 'category_id' => $category->id]);

    $this->getJson('/api/v1/budgets?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.items.0.category.id', $category->id)
        ->assertJsonPath('data.items.0.amount', 50000)
        ->assertJsonPath('data.items.0.source', 'default')
        ->assertJsonPath('data.items.0.spent', 20000)
        ->assertJsonPath('data.items.0.remaining', 30000)
        ->assertJsonPath('data.items.0.percent', 40);
});

it('usa a exceção do mês quando existe, sem afetar outros meses', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 90000, 'month' => '2026-10-01']);

    $this->getJson('/api/v1/budgets?month=2026-10')
        ->assertJsonPath('data.items.0.amount', 90000)
        ->assertJsonPath('data.items.0.source', 'override');

    $this->getJson('/api/v1/budgets?month=2026-11')
        ->assertJsonPath('data.items.0.amount', 50000)
        ->assertJsonPath('data.items.0.source', 'default');
});

it('orçamento da categoria-pai cobre o gasto dela e das filhas', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    $child = Category::factory()->create(['name' => 'Mercado', 'parent_id' => $parent->id]);
    Budget::factory()->create(['category_id' => $parent->id, 'amount' => 100000]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 10000, 'category_id' => $parent->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-06', 'amount' => 25000, 'category_id' => $child->id]);

    $this->getJson('/api/v1/budgets?month=2026-10')
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.category.id', $parent->id)
        ->assertJsonPath('data.items.0.spent', 35000);
});

it('filha com orçamento próprio conta no dela e também no total do pai', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    $child = Category::factory()->create(['name' => 'Mercado', 'parent_id' => $parent->id]);
    Budget::factory()->create(['category_id' => $parent->id, 'amount' => 100000]);
    Budget::factory()->create(['category_id' => $child->id, 'amount' => 30000]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 10000, 'category_id' => $parent->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-06', 'amount' => 25000, 'category_id' => $child->id]);

    $response = $this->getJson('/api/v1/budgets?month=2026-10')->assertOk();
    $items = collect($response->json('data.items'))->keyBy('category.id');

    expect($items[$parent->id]['spent'])->toBe(35000)
        ->and($items[$child->id]['spent'])->toBe(25000)
        ->and($response->json('data.totals.spent'))->toBe(60000)
        ->and($response->json('data.totals.budgeted'))->toBe(130000);
});

it('abate estornos (entradas não-transferência) do gasto, com mínimo zero', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 20000, 'category_id' => $category->id]);
    Transaction::factory()->for($this->account)->income()->create(['date' => '2026-10-06', 'amount' => 30000, 'category_id' => $category->id]);

    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonPath('data.items.0.spent', 0);
});

it('ignora transferências e lançamentos ignorados no gasto', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);
    $other = Account::factory()->create();

    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 5000, 'category_id' => $category->id, 'is_ignored' => true]);

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->account->id, 'to_account_id' => $other->id,
        'date' => '2026-10-06', 'amount' => 40000, 'description' => 'Reserva',
    ])->assertCreated();

    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonPath('data.items.0.spent', 0);
});

it('restringe o gasto à moeda principal', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);
    $usd = Account::factory()->create(['currency' => 'USD']);

    Transaction::factory()->for($usd)->create(['date' => '2026-10-05', 'amount' => 20000, 'currency' => 'USD', 'category_id' => $category->id]);

    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonPath('data.items.0.spent', 0);
});

it('soma em unbudgeted_spent as categorias de despesa sem orçamento e os lançamentos sem categoria', function () {
    $budgeted = Category::factory()->create(['name' => 'Orçada']);
    $unbudgeted = Category::factory()->create(['name' => 'Sem orçamento']);
    Budget::factory()->create(['category_id' => $budgeted->id, 'amount' => 50000]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 10000, 'category_id' => $budgeted->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-06', 'amount' => 15000, 'category_id' => $unbudgeted->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-07', 'amount' => 7000, 'category_id' => null]);

    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonPath('data.unbudgeted_spent', 22000);
});

it('isola orçamento e gasto de outro usuário', function () {
    $user = auth()->user();
    $category = Category::factory()->create(['name' => 'Minha categoria']);
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 10000, 'category_id' => $category->id]);

    actingAsUser();
    $otherAccount = Account::factory()->create();
    $otherCategory = Category::factory()->create(['name' => 'Categoria de outro usuário']);
    Budget::factory()->create(['category_id' => $otherCategory->id, 'amount' => 999999]);
    Transaction::factory()->for($otherAccount)->create(['date' => '2026-10-05', 'amount' => 888888, 'category_id' => $otherCategory->id]);

    $this->actingAs($user);

    $this->getJson('/api/v1/budgets?month=2026-10')
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.category.name', 'Minha categoria')
        ->assertJsonPath('data.items.0.spent', 10000);
});

it('usa o mês atual quando não informado e valida o formato', function () {
    $this->getJson('/api/v1/budgets')->assertJsonPath('data.month', now()->format('Y-m'));
    $this->getJson('/api/v1/budgets?month=10-2026')->assertStatus(422)->assertJsonValidationErrors('month');
});
