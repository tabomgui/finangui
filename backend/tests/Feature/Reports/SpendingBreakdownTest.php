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

it('exclui categoria marcada como transferência', function () {
    $transferCategory = Category::factory()->transfer()->create(['name' => 'Investimentos']);
    $category = Category::factory()->create(['name' => 'Mercado']);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 10000, 'category_id' => $transferCategory->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 5000, 'category_id' => $category->id]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    expect(collect($response->json('data.categories'))->pluck('name')->all())->toBe(['Mercado'])
        ->and($response->json('data.total'))->toBe(5000);
});

it('exclui subcategoria cujo pai é marcado como transferência', function () {
    $transferParent = Category::factory()->transfer()->create(['name' => 'Investimentos']);
    $child = Category::factory()->create(['name' => 'Tesouro', 'parent_id' => $transferParent->id, 'is_transfer' => false]);
    $category = Category::factory()->create(['name' => 'Mercado']);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 10000, 'category_id' => $child->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 5000, 'category_id' => $category->id]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    expect(collect($response->json('data.categories'))->pluck('name')->all())->toBe(['Mercado'])
        ->and($response->json('data.total'))->toBe(5000);
});

it('inclui lançamentos exatamente em from e to, exclui o dia antes e o dia depois', function () {
    $category = Category::factory()->create();

    Transaction::factory()->for($this->account)->create(['date' => '2025-12-31', 'amount' => 1000, 'category_id' => $category->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-01', 'amount' => 2000, 'category_id' => $category->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-31', 'amount' => 4000, 'category_id' => $category->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-02-01', 'amount' => 8000, 'category_id' => $category->id]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    expect($response->json('data.total'))->toBe(6000);
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

it('desempata categorias com o mesmo valor por nome asc', function () {
    $zebra = Category::factory()->create(['name' => 'Zebra']);
    $alpha = Category::factory()->create(['name' => 'Alpha']);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 5000, 'category_id' => $zebra->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 5000, 'category_id' => $alpha->id]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    expect(collect($response->json('data.categories'))->pluck('name')->all())->toBe(['Alpha', 'Zebra']);
});

it('desempata subcategorias com o mesmo valor por nome asc', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    $zebra = Category::factory()->create(['name' => 'Zebra', 'parent_id' => $parent->id]);
    $alpha = Category::factory()->create(['name' => 'Alpha', 'parent_id' => $parent->id]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 5000, 'category_id' => $zebra->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 5000, 'category_id' => $alpha->id]);

    $response = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    $item = collect($response->json('data.categories'))->first();
    expect(collect($item['children'])->pluck('name')->all())->toBe(['Alpha', 'Zebra']);
});

it('exige from <= to', function () {
    $this->getJson('/api/v1/reports/spending?from=2026-02-01&to=2026-01-01')
        ->assertStatus(422)->assertJsonValidationErrors('to');
});

it('exige from e to, rejeitando ausência ou formato inválido', function () {
    $this->getJson('/api/v1/reports/spending?to=2026-01-31')
        ->assertStatus(422)->assertJsonValidationErrors('from');

    $this->getJson('/api/v1/reports/spending?from=2026-01-01')
        ->assertStatus(422)->assertJsonValidationErrors('to');

    $this->getJson('/api/v1/reports/spending?from=01-01-2026&to=2026-01-31')
        ->assertStatus(422)->assertJsonValidationErrors('from');

    $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=not-a-date')
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

it('a lista de lançamentos do detalhamento bate com a entrada do gráfico', function () {
    $parent = Category::factory()->create(['name' => 'Alimentação']);
    $child = Category::factory()->create(['name' => 'Mercado', 'parent_id' => $parent->id]);

    Transaction::factory()->for($this->account)->create(['date' => '2026-01-05', 'amount' => 10000, 'category_id' => $parent->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-06', 'amount' => 12000, 'category_id' => $parent->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-07', 'amount' => 30000, 'category_id' => $child->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-08', 'amount' => 15000, 'category_id' => $child->id]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-09', 'amount' => 7000, 'category_id' => null]);
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-10', 'amount' => 3000, 'category_id' => null]);
    // Fora da base do gráfico (ignorado): não pode entrar em nenhum dos dois lados.
    Transaction::factory()->for($this->account)->create(['date' => '2026-01-11', 'amount' => 999, 'category_id' => $child->id, 'is_ignored' => true]);

    $breakdown = $this->getJson('/api/v1/reports/spending?from=2026-01-01&to=2026-01-31')->assertOk();

    $item = collect($breakdown->json('data.categories'))->firstWhere('category_id', $parent->id);
    $children = collect($item['children'])->keyBy('category_id');
    $noCategoryItem = collect($breakdown->json('data.categories'))->first(fn (array $c) => ! array_key_exists('category_id', $c));

    $assertDrillDownMatches = function (string $query, array $chartEntry) {
        $response = $this->getJson('/api/v1/transactions?'.$query.'&reportable=1&direction=out&from=2026-01-01&to=2026-01-31&currency=BRL')
            ->assertOk();
        $rows = collect($response->json('data'));

        expect($rows)->toHaveCount($chartEntry['count'])
            ->and((int) $rows->sum('amount'))->toBe($chartEntry['amount']);
    };

    // Subcategoria.
    $assertDrillDownMatches("category_id={$child->id}&category_exact=1", $children[$child->id]);
    // Entrada "direto no pai".
    $assertDrillDownMatches("category_id={$parent->id}&category_exact=1", $children[$parent->id]);
    // "Sem categoria".
    $assertDrillDownMatches('no_category=1', $noCategoryItem);
});
