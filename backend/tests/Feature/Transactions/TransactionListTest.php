<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;

it('lista só transações do usuário, da mais recente para a mais antiga', function () {
    $other = User::factory()->create();
    Transaction::factory()->create(['account_id' => Account::factory()->create(['user_id' => $other->id])->id]);

    actingAsUser();
    Transaction::factory()->create(['date' => '2026-09-01', 'description' => 'antiga']);
    Transaction::factory()->create(['date' => '2026-10-01', 'description' => 'nova']);

    $this->getJson('/api/v1/transactions')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.description', 'nova')
        ->assertJsonPath('data.1.description', 'antiga');
});

it('pagina por cursor', function () {
    actingAsUser();
    Transaction::factory()->count(3)->sequence(
        ['date' => '2026-10-03'], ['date' => '2026-10-02'], ['date' => '2026-10-01'],
    )->create();

    $first = $this->getJson('/api/v1/transactions?per_page=2')->assertJsonCount(2, 'data');
    $cursor = $first->json('meta.next_cursor');

    expect($cursor)->not->toBeNull();

    $this->getJson('/api/v1/transactions?per_page=2&cursor='.urlencode($cursor))
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.date', '2026-10-01')
        ->assertJsonPath('meta.next_cursor', null);
});

it('filtra por conta, período, sentido e busca', function () {
    actingAsUser();
    $inter = Account::factory()->create();
    $nubank = Account::factory()->create();
    Transaction::factory()->create(['account_id' => $inter->id, 'date' => '2026-10-05', 'direction' => 'out', 'description' => 'Uber *trip']);
    Transaction::factory()->create(['account_id' => $inter->id, 'date' => '2026-09-05', 'direction' => 'out', 'description' => 'Mercado']);
    Transaction::factory()->create(['account_id' => $nubank->id, 'date' => '2026-10-06', 'direction' => 'in', 'description' => 'Salário']);

    $this->getJson("/api/v1/transactions?account_id={$inter->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/transactions?from=2026-10-01&to=2026-10-31')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/transactions?direction=in')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/transactions?search=uber')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.description', 'Uber *trip');
});

it('filtro por categoria inclui as subcategorias', function () {
    actingAsUser();
    $food = Category::factory()->create();
    $market = Category::factory()->create(['parent_id' => $food->id]);
    $other = Category::factory()->create();
    Transaction::factory()->create(['category_id' => $food->id]);
    Transaction::factory()->create(['category_id' => $market->id]);
    Transaction::factory()->create(['category_id' => $other->id]);

    $this->getJson("/api/v1/transactions?category_id={$food->id}")->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/transactions?category_id={$market->id}")->assertJsonCount(1, 'data');
});

it('filtra por tag', function () {
    actingAsUser();
    $tag = Tag::factory()->create();
    $tagged = Transaction::factory()->create();
    $tagged->tags()->attach($tag);
    Transaction::factory()->create();

    $this->getJson("/api/v1/transactions?tag_id={$tag->id}")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $tagged->id);
});

it('escapa curingas na busca', function () {
    actingAsUser();
    Transaction::factory()->create(['description' => '100% natural']);
    Transaction::factory()->create(['description' => 'outra']);

    $this->getJson('/api/v1/transactions?search='.urlencode('%'))->assertJsonCount(1, 'data');
});
