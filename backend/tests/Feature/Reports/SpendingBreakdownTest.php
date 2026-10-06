<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Models\Transaction;

beforeEach(function () {
    actingAsUser();
    $this->account = Account::factory()->create();
});

it('agrupa subcategoria na categoria pai', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    $child = Category::factory()->create(['name' => 'Mercado', 'parent_id' => $parent->id]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-06', 'amount' => 20000, 'category_id' => $child->id]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    $categories = collect($response->json('data.categories'));
    expect($categories)->toHaveCount(1);

    $item = $categories->first();
    expect($item['category_id'])->toBe($parent->id)
        ->and($item['name'])->toBe('Alimentação')
        ->and($item['amount'])->toBe(20000)
        ->and($item['count'])->toBe(1)
        ->and($item['children'])->toHaveCount(1);

    $childItem = $item['children'][0];
    expect($childItem['category_id'])->toBe($child->id)
        ->and($childItem['name'])->toBe('Mercado')
        ->and($childItem['amount'])->toBe(20000)
        ->and($childItem)->not->toHaveKey('direct');
});

it('não abre detalhamento quando só há gasto direto no pai, sem subcategoria gasta', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    Category::factory()->create(['name' => 'Mercado', 'parent_id' => $parent->id]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 15000, 'category_id' => $parent->id]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    $item = collect($response->json('data.categories'))->first();
    expect($item['amount'])->toBe(15000)
        ->and($item['children'])->toBe([]);
});

it('adiciona uma entrada "direto" quando há gasto no pai e em alguma subcategoria', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    $child = Category::factory()->create(['name' => 'Mercado', 'parent_id' => $parent->id]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 10000, 'category_id' => $parent->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-06', 'amount' => 30000, 'category_id' => $child->id]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    $item = collect($response->json('data.categories'))->first();
    expect($item['amount'])->toBe(40000)
        ->and($item['children'])->toHaveCount(2);

    $children = collect($item['children'])->keyBy('category_id');
    expect($children[$child->id]['amount'])->toBe(30000)
        ->and($children[$child->id])->not->toHaveKey('direct')
        ->and($children[$parent->id]['amount'])->toBe(10000)
        ->and($children[$parent->id]['direct'])->toBeTrue()
        ->and($children[$parent->id]['name'])->toBe('Alimentação');

    // children ordenado desc por valor.
    expect(collect($item['children'])->pluck('category_id')->all())->toBe([$child->id, $parent->id]);
});

it('separa lançamentos sem categoria, sem expor category_id', function () {
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 7000, 'category_id' => null]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-06', 'amount' => 3000, 'category_id' => null]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    $item = collect($response->json('data.categories'))->first();
    expect($item['name'])->toBe('Sem categoria')
        ->and($item)->not->toHaveKey('category_id')
        ->and($item['amount'])->toBe(10000)
        ->and($item['count'])->toBe(2)
        ->and($item['children'])->toBe([]);
});

it('exclui transferências, ignorados, pendentes, projetados, receitas e outra moeda', function () {
    $category = Category::factory()->create();
    $other = Account::factory()->create();
    $usd = Account::factory()->create(['currency' => 'USD']);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 1000, 'category_id' => $category->id, 'is_ignored' => true]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-06', 'amount' => 2000, 'category_id' => $category->id, 'status' => 'pending']);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-07', 'amount' => 3000, 'category_id' => $category->id, 'status' => 'projected']);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-08', 'amount' => 4000, 'category_id' => $category->id, 'direction' => 'in']);
    Transaction::factory()->for($usd)->create(['date' => '2026-01-09', 'amount' => 5000, 'currency' => 'USD', 'category_id' => $category->id]);
    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->account->id, 'to_account_id' => $other->id,
        'date' => '2026-01-10', 'amount' => 6000, 'description' => 'Reserva',
    ])->assertCreated();

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-11', 'amount' => 7000, 'category_id' => $category->id]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    expect($response->json('data.total'))->toBe(7000)
        ->and(collect($response->json('data.categories')))->toHaveCount(1);
});

it('ordena categorias por valor desc', function () {
    $small = Category::factory()->create(['name' => 'Pequena']);
    $big = Category::factory()->create(['name' => 'Grande']);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 5000, 'category_id' => $small->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 90000, 'category_id' => $big->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 7000, 'category_id' => null]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    $names = collect($response->json('data.categories'))->pluck('name');
    expect($names->all())->toBe(['Grande', 'Sem categoria', 'Pequena']);
});

it('exige from <= to', function () {
    $this->getJson('/api/v1/reports/spending?from=2026-02-01&to=2026-01-01')
        ->assertStatus(422)->assertJsonValidationErrors('to');
});

it('limita o período a 366 dias', function () {
    $this->getJson('/api/v1/reports/spending?from=2025-01-01&to=2026-01-02')
        ->assertStatus(422)->assertJsonValidationErrors('to');

    $this->getJson('/api/v1/reports/spending?from=2025-01-01&to=2026-01-01')->assertOk();
});

it('isola a distribuição de gastos por usuário', function () {
    $user = auth()->user();
    $category = Category::factory()->create(['name' => 'Minha categoria']);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 10000, 'category_id' => $category->id]);

    actingAsUser();
    $otherAccount = Account::factory()->create();
    $otherCategory = Category::factory()->create(['name' => 'Categoria de outro usuário']);
    Transaction::factory()->for($otherAccount)->create(['date' => '2026-01-05', 'amount' => 999999, 'category_id' => $otherCategory->id]);

    $this->actingAs($user);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    expect(collect($response->json('data.categories'))->pluck('name')->all())->toBe(['Minha categoria']);
});
