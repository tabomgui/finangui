<?php

use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Support\HistoryCategorizer;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Str;

beforeEach(function () {
    actingAsUser();
    $this->history = new HistoryCategorizer;
});

it('sugere a categoria mais frequente para a mesma chave e direção', function () {
    $a = Category::factory()->create();
    $b = Category::factory()->create();

    Transaction::factory()->count(2)->create(['description' => 'Uber trip 1', 'direction' => Direction::Out, 'category_id' => $a->id]);
    Transaction::factory()->create(['description' => 'Uber trip 2', 'direction' => Direction::Out, 'category_id' => $b->id]);

    expect($this->history->suggest('UBER TRIP', Direction::Out))->toBe($a->id);
});

it('em empate, usa a categoria da transação mais recente', function () {
    $a = Category::factory()->create();
    $b = Category::factory()->create();

    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $a->id, 'date' => '2026-01-01']);
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $b->id, 'date' => '2026-02-01']);

    expect($this->history->suggest('UBER', Direction::Out))->toBe($b->id);
});

it('direção diferente não conta', function () {
    $category = Category::factory()->create();

    Transaction::factory()->create(['description' => 'Pix', 'direction' => Direction::In, 'category_id' => $category->id]);

    expect($this->history->suggest('PIX', Direction::Out))->toBeNull();
});

it('chave vazia devolve nulo sem consultar', function () {
    expect($this->history->suggest('', Direction::Out))->toBeNull();
});

it('categoria arquivada fica fora da sugestão', function () {
    $archived = Category::factory()->create(['is_archived' => true]);
    $active = Category::factory()->create();

    Transaction::factory()->count(3)->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $archived->id]);
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $active->id]);

    expect($this->history->suggest('UBER', Direction::Out))->toBe($active->id);
});

it('pernas de transferência e transações ignoradas ficam fora', function () {
    $category = Category::factory()->create();

    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $category->id, 'transfer_id' => (string) Str::uuid()]);
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $category->id, 'is_ignored' => true]);

    expect($this->history->suggest('UBER', Direction::Out))->toBeNull();
});

it('isola o histórico entre usuários', function () {
    actingAsUser();
    $category = Category::factory()->create();
    Transaction::factory()->create(['description' => 'Uber', 'direction' => Direction::Out, 'category_id' => $category->id]);

    actingAsUser();
    expect($this->history->suggest('UBER', Direction::Out))->toBeNull();
});
