<?php

use App\Domain\Budgets\Models\Budget;
use App\Domain\Categories\Models\Category;

beforeEach(function () {
    actingAsUser();
});

it('cria o padrão mensal quando month não é informado', function () {
    $category = Category::factory()->create();

    $this->putJson('/api/v1/budgets', ['category_id' => $category->id, 'amount' => 50000])
        ->assertOk()
        ->assertJsonPath('data.items.0.amount', 50000)
        ->assertJsonPath('data.items.0.source', 'default');

    expect(Budget::query()->where('category_id', $category->id)->whereNull('month')->count())->toBe(1);
});

it('cria a exceção do mês informado sem tocar no padrão', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);

    $this->putJson('/api/v1/budgets', ['category_id' => $category->id, 'amount' => 90000, 'month' => '2026-10'])
        ->assertOk()
        ->assertJsonPath('data.month', '2026-10')
        ->assertJsonPath('data.items.0.amount', 90000)
        ->assertJsonPath('data.items.0.source', 'override');

    expect(Budget::query()->where('category_id', $category->id)->whereNull('month')->first()->amount->cents)->toBe(50000);
});

it('upsert: atualiza o valor em vez de duplicar a linha', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);

    $this->putJson('/api/v1/budgets', ['category_id' => $category->id, 'amount' => 70000])->assertOk();

    expect(Budget::query()->where('category_id', $category->id)->count())->toBe(1)
        ->and(Budget::query()->where('category_id', $category->id)->first()->amount->cents)->toBe(70000);
});

it('rejeita categoria de receita, arquivada, de transferência ou de outro usuário', function () {
    $user = auth()->user();
    $income = Category::factory()->income()->create();
    $archived = Category::factory()->create(['is_archived' => true]);
    $transferCategory = Category::factory()->transfer()->create();
    $childOfTransfer = Category::factory()->create(['parent_id' => $transferCategory->id, 'is_transfer' => false]);

    actingAsUser();
    $otherUsersCategory = Category::factory()->create();
    $this->actingAs($user);

    $this->putJson('/api/v1/budgets', ['category_id' => $income->id, 'amount' => 1000])->assertStatus(422)->assertJsonValidationErrors('category_id');
    $this->putJson('/api/v1/budgets', ['category_id' => $archived->id, 'amount' => 1000])->assertStatus(422)->assertJsonValidationErrors('category_id');
    $this->putJson('/api/v1/budgets', ['category_id' => $transferCategory->id, 'amount' => 1000])->assertStatus(422)->assertJsonValidationErrors('category_id');
    $this->putJson('/api/v1/budgets', ['category_id' => $childOfTransfer->id, 'amount' => 1000])->assertStatus(422)->assertJsonValidationErrors('category_id');
    $this->putJson('/api/v1/budgets', ['category_id' => $otherUsersCategory->id, 'amount' => 1000])->assertStatus(422)->assertJsonValidationErrors('category_id');
});

it('exclui o padrão mensal', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);

    $this->deleteJson('/api/v1/budgets', ['category_id' => $category->id])->assertNoContent();

    expect(Budget::query()->count())->toBe(0);
});

it('exclui só a exceção do mês informado, mantendo o padrão', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 90000, 'month' => '2026-10-01']);

    $this->deleteJson('/api/v1/budgets', ['category_id' => $category->id, 'month' => '2026-10'])->assertNoContent();

    expect(Budget::query()->where('category_id', $category->id)->count())->toBe(1)
        ->and(Budget::query()->where('category_id', $category->id)->first()->month)->toBeNull();
});

it('é idempotente: excluir um orçamento inexistente ainda responde 204', function () {
    $category = Category::factory()->create();

    $this->deleteJson('/api/v1/budgets', ['category_id' => $category->id])->assertNoContent();
});

it('rejeita excluir orçamento de categoria de outro usuário', function () {
    $category = Category::factory()->create();
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 50000]);

    actingAsUser();

    $this->deleteJson('/api/v1/budgets', ['category_id' => $category->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('category_id');
});
