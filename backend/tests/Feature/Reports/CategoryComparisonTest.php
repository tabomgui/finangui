<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Models\Transaction;

beforeEach(function () {
    actingAsUser();
    $this->account = Account::factory()->create();
});

it('compara despesa por categoria-raiz entre dois períodos, somando filhas no pai', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    $child = Category::factory()->create(['name' => 'Mercado', 'parent_id' => $parent->id]);

    // Período A (janeiro): 10000 (pai) + 20000 (filha) = 30000.
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 10000, 'category_id' => $parent->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-06', 'amount' => 20000, 'category_id' => $child->id]);
    // Período B (fevereiro): 50000 no pai.
    Transaction::factory()->for($this->account)->create(['date' => '2026-02-05', 'amount' => 50000, 'category_id' => $parent->id]);

    $response = $this->getJson('/api/v1/reports/categories?a_from=2026-01&a_to=2026-01&b_from=2026-02&b_to=2026-02')->assertOk();

    $items = collect($response->json('data.items'))->keyBy('category_id');
    $item = $items[$parent->id];

    expect($items)->toHaveCount(1)
        ->and($item['a'])->toBe(30000)
        ->and($item['b'])->toBe(50000)
        ->and($item['delta'])->toBe(20000)
        ->and($item['delta_percent'])->toBe(67)
        ->and($response->json('data.totals'))->toBe(['a' => 30000, 'b' => 50000]);
});

it('omite delta_percent quando a é zero', function () {
    $category = Category::factory()->create();
    Transaction::factory()->for($this->account)->create(['date' => '2026-02-05', 'amount' => 10000, 'category_id' => $category->id]);

    $response = $this->getJson('/api/v1/reports/categories?a_from=2026-01&a_to=2026-01&b_from=2026-02&b_to=2026-02')->assertOk();

    $item = collect($response->json('data.items'))->firstWhere('category_id', $category->id);

    expect($item)->not->toHaveKey('delta_percent')
        ->and($item['a'])->toBe(0)
        ->and($item['b'])->toBe(10000);
});

it('separa lançamentos sem categoria e ordena pelo maior entre a e b', function () {
    $small = Category::factory()->create(['name' => 'Pequena']);
    $big = Category::factory()->create(['name' => 'Grande']);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 5000, 'category_id' => $small->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 90000, 'category_id' => $big->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 7000, 'category_id' => null]);

    $response = $this->getJson('/api/v1/reports/categories?a_from=2026-01&a_to=2026-01&b_from=2026-01&b_to=2026-01')->assertOk();

    $names = collect($response->json('data.items'))->pluck('name');
    expect($names->all())->toBe(['Grande', 'Sem categoria', 'Pequena']);
});

it('move a compra do cartão para o período do vencimento da fatura em basis=statement', function () {
    $card = Account::factory()->creditCard(closingDay: 3, dueDay: 10)->create();
    $category = Category::factory()->create();

    $this->postJson('/api/v1/transactions', [
        'account_id' => $card->id, 'date' => '2026-01-15', 'amount' => 40000, 'direction' => 'out',
        'description' => 'Compra', 'category_id' => $category->id,
    ])->assertCreated();

    $purchase = $this->getJson('/api/v1/reports/categories?a_from=2026-01&a_to=2026-01&b_from=2026-02&b_to=2026-02&basis=purchase')->assertOk();
    expect(collect($purchase->json('data.items'))->firstWhere('category_id', $category->id)['a'])->toBe(40000);

    $statement = $this->getJson('/api/v1/reports/categories?a_from=2026-01&a_to=2026-01&b_from=2026-02&b_to=2026-02&basis=statement')->assertOk();
    expect(collect($statement->json('data.items'))->firstWhere('category_id', $category->id)['b'])->toBe(40000);
});

it('exige from <= to em cada período', function () {
    $this->getJson('/api/v1/reports/categories?a_from=2026-02&a_to=2026-01&b_from=2026-01&b_to=2026-02')
        ->assertStatus(422)->assertJsonValidationErrors('a_to');

    $this->getJson('/api/v1/reports/categories?a_from=2026-01&a_to=2026-01&b_from=2026-02&b_to=2026-01')
        ->assertStatus(422)->assertJsonValidationErrors('b_to');
});

it('isola a comparação por categoria por usuário', function () {
    $user = auth()->user();
    $category = Category::factory()->create(['name' => 'Minha categoria']);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 10000, 'category_id' => $category->id]);

    actingAsUser();
    $otherAccount = Account::factory()->create();
    $otherCategory = Category::factory()->create(['name' => 'Categoria de outro usuário']);
    Transaction::factory()->for($otherAccount)->create(['date' => '2026-01-05', 'amount' => 999999, 'category_id' => $otherCategory->id]);

    $this->actingAs($user);

    $response = $this->getJson('/api/v1/reports/categories?a_from=2026-01&a_to=2026-01&b_from=2026-01&b_to=2026-01')->assertOk();

    expect(collect($response->json('data.items'))->pluck('name')->all())->toBe(['Minha categoria']);
});
