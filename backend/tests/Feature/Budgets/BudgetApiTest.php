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
        ->assertJsonPath('data.items.0.percent', 40)
        ->assertJsonPath('data.totals.remaining', 30000);
});

it('totals.remaining fica negativo quando o gasto passa do orçado', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 20000]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 35000, 'category_id' => $category->id]);

    $this->getJson('/api/v1/budgets?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.totals.budgeted', 20000)
        ->assertJsonPath('data.totals.spent', 35000)
        ->assertJsonPath('data.totals.remaining', -15000);
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

it('filha com orçamento próprio conta no dela e também no total do pai, mas os totais não contam o gasto da filha duas vezes', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    $child = Category::factory()->create(['name' => 'Mercado', 'parent_id' => $parent->id]);
    Budget::factory()->create(['category_id' => $parent->id, 'amount' => 100000]);
    Budget::factory()->create(['category_id' => $child->id, 'amount' => 30000]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 10000, 'category_id' => $parent->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-06', 'amount' => 25000, 'category_id' => $child->id]);

    $response = $this->getJson('/api/v1/budgets?month=2026-10')->assertOk();
    $items = collect($response->json('data.items'))->keyBy('category.id');

    // O item da filha mostra o próprio orçamento e gasto normalmente, mas o
    // gasto dela já está dentro do total do pai (spent da filha somado ao
    // do pai) — os totais só contam quem não tem pai também orçado neste
    // mês, senão o gasto da filha entraria duas vezes.
    expect($items[$parent->id]['spent'])->toBe(35000)
        ->and($items[$child->id]['spent'])->toBe(25000)
        ->and($response->json('data.totals.spent'))->toBe(35000)
        ->and($response->json('data.totals.budgeted'))->toBe(100000)
        ->and($response->json('data.totals.remaining'))->toBe(65000);
});

it('filha orçada com pai sem orçamento conta normalmente nos totais', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    $child = Category::factory()->create(['name' => 'Mercado', 'parent_id' => $parent->id]);
    Budget::factory()->create(['category_id' => $child->id, 'amount' => 30000]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-10-06', 'amount' => 25000, 'category_id' => $child->id]);

    $response = $this->getJson('/api/v1/budgets?month=2026-10')->assertOk();

    expect($response->json('data.items'))->toHaveCount(1)
        ->and($response->json('data.totals.spent'))->toBe(25000)
        ->and($response->json('data.totals.budgeted'))->toBe(30000)
        ->and($response->json('data.totals.remaining'))->toBe(5000);
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

it('entrada sem categoria não abate o gasto sem categoria: não é estorno de nada', function () {
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 7000, 'category_id' => null]);
    Transaction::factory()->for($this->account)->income()->create(['date' => '2026-10-06', 'amount' => 20000, 'category_id' => null]);

    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonPath('data.unbudgeted_spent', 7000);
});

it('clampa cada categoria sem orçamento a zero antes de somar em unbudgeted_spent', function () {
    $refunded = Category::factory()->create(['name' => 'Estornada']);
    $spent = Category::factory()->create(['name' => 'Gasta']);

    // Estorno maior que o gasto: líquido negativo, não pode abater a outra categoria.
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 1000, 'category_id' => $refunded->id]);
    Transaction::factory()->for($this->account)->income()->create(['date' => '2026-10-06', 'amount' => 9000, 'category_id' => $refunded->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-07', 'amount' => 5000, 'category_id' => $spent->id]);

    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonPath('data.unbudgeted_spent', 5000);
});

it('esconde o item quando a categoria (ou o pai) passa a ser de transferência', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);

    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonCount(1, 'data.items');

    $category->update(['is_transfer' => true]);

    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonCount(0, 'data.items');
});

it('esconde o item da filha quando o pai passa a ser de transferência', function () {
    $parent = Category::factory()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);
    Budget::factory()->create(['category_id' => $child->id, 'amount' => 30000]);

    $parent->update(['is_transfer' => true]);

    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonCount(0, 'data.items');
});

it('exceção de valor 0 cancela o orçamento do mês: some dos items/totals e o gasto vai para unbudgeted_spent', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 0, 'month' => '2026-10-01']);

    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 12000, 'category_id' => $category->id]);

    $october = $this->getJson('/api/v1/budgets?month=2026-10')->assertOk();
    expect($october->json('data.items'))->toHaveCount(0)
        ->and($october->json('data.totals'))->toBe(['budgeted' => 0, 'spent' => 0, 'remaining' => 0])
        ->and($october->json('data.unbudgeted_spent'))->toBe(12000);

    // O padrão mensal continua valendo nos outros meses.
    $this->getJson('/api/v1/budgets?month=2026-11')
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.amount', 50000);
});

it('percent arredonda para baixo: 100 só quando de fato bate o orçamento', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 30000]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-10-05', 'amount' => 20000, 'category_id' => $category->id]);

    // 20000 / 30000 = 66,67%: arredondar para cima daria 67.
    $this->getJson('/api/v1/budgets?month=2026-10')->assertJsonPath('data.items.0.percent', 66);
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
