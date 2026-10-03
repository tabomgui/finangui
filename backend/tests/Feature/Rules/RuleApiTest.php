<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Models\Rule;
use App\Domain\Tags\Models\Tag;
use App\Models\User;

function validRulePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Uber',
        'match' => 'all',
        'conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => 'uber'],
        ],
        'actions' => [
            ['type' => 'set_category', 'category_id' => Category::factory()->create()->id],
        ],
    ], $overrides);
}

it('cria uma regra válida com prioridade incremental', function () {
    actingAsUser();

    $first = $this->postJson('/api/v1/rules', validRulePayload(['name' => 'Primeira']))
        ->assertCreated()
        ->json('data');

    expect($first['priority'])->toBe(0);
    expect($first['name'])->toBe('Primeira');
    expect($first['is_active'])->toBeTrue();
    expect($first['match'])->toBe('all');
    expect($first['conditions'])->toBe([
        ['field' => 'description', 'op' => 'contains', 'value' => 'uber'],
    ]);
    expect($first['actions'][0]['type'])->toBe('set_category');
    expect($first['last_applied_at'])->toBeNull();
    expect($first['last_applied_changes'])->toBeNull();

    $second = $this->postJson('/api/v1/rules', validRulePayload(['name' => 'Segunda']))
        ->assertCreated()
        ->json('data');

    expect($second['priority'])->toBe(1);
});

it('descarta chaves extras ao gravar condições e ações', function () {
    actingAsUser();
    $category = Category::factory()->create();

    $response = $this->postJson('/api/v1/rules', [
        'name' => 'Mercado',
        'match' => 'all',
        'conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => 'mercado', 'bogus' => 'x'],
        ],
        'actions' => [
            ['type' => 'set_category', 'category_id' => $category->id, 'bogus' => 'x'],
        ],
    ])->assertCreated();

    $response->assertJsonPath('data.conditions.0', ['field' => 'description', 'op' => 'contains', 'value' => 'mercado']);
    $response->assertJsonPath('data.actions.0', ['type' => 'set_category', 'category_id' => $category->id]);
});

it('lista as regras do usuário em ordem de prioridade e depois id', function () {
    actingAsUser();
    $other = User::factory()->create();

    $a = Rule::factory()->create(['priority' => 1]);
    $b = Rule::factory()->create(['priority' => 1]);
    $c = Rule::factory()->create(['priority' => 0]);
    Rule::factory()->create(['user_id' => $other->id]);

    $ids = $this->getJson('/api/v1/rules')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$c->id, $a->id, $b->id]);
});

it('422 com o caminho do erro vindo do validador de definição', function () {
    actingAsUser();

    $this->postJson('/api/v1/rules', validRulePayload([
        'conditions' => [
            ['field' => 'amount', 'op' => 'contains', 'value' => 100],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('conditions.0.op');

    $this->postJson('/api/v1/rules', validRulePayload([
        'conditions' => [
            ['field' => 'description', 'op' => 'regex', 'value' => '.*'],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('conditions.0.value');
});

it('422 quando set_category referencia categoria de outro usuário', function () {
    actingAsUser();
    $other = User::factory()->create();
    $otherCategory = Category::factory()->create(['user_id' => $other->id]);

    $this->postJson('/api/v1/rules', validRulePayload([
        'actions' => [
            ['type' => 'set_category', 'category_id' => $otherCategory->id],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('actions.0.category_id');
});

it('422 quando add_tag referencia tag de outro usuário', function () {
    actingAsUser();
    $other = User::factory()->create();
    $otherTag = Tag::factory()->create(['user_id' => $other->id]);

    $this->postJson('/api/v1/rules', validRulePayload([
        'actions' => [
            ['type' => 'add_tag', 'tag_id' => $otherTag->id],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('actions.0.tag_id');
});

it('422 quando condição account_id referencia conta de outro usuário, inclusive dentro de grupo', function () {
    actingAsUser();
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);

    $this->postJson('/api/v1/rules', validRulePayload([
        'conditions' => [
            ['field' => 'account_id', 'op' => 'equals', 'value' => $otherAccount->id],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('conditions.0.value');

    $this->postJson('/api/v1/rules', validRulePayload([
        'conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => 'x'],
            ['match' => 'any', 'conditions' => [
                ['field' => 'account_id', 'op' => 'equals', 'value' => $otherAccount->id],
            ]],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('conditions.1.conditions.0.value');
});

it('PATCH só name não revalida o resto', function () {
    actingAsUser();
    $rule = Rule::factory()->create(['name' => 'Antiga']);

    $this->patchJson("/api/v1/rules/{$rule->id}", ['name' => 'Nova'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Nova');

    expect($rule->refresh()->conditions)->not->toBeEmpty();
});

it('PATCH só is_active funciona', function () {
    actingAsUser();
    $rule = Rule::factory()->create(['is_active' => true]);

    $this->patchJson("/api/v1/rules/{$rule->id}", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});

it('PATCH conditions inválidas retorna 422', function () {
    actingAsUser();
    $rule = Rule::factory()->create();

    $this->patchJson("/api/v1/rules/{$rule->id}", [
        'conditions' => [
            ['field' => 'amount', 'op' => 'contains', 'value' => 1],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('conditions.0.op');
});

it('PATCH actions sem mandar conditions valida o conjunto atual mais o novo', function () {
    actingAsUser();
    $other = User::factory()->create();
    $otherCategory = Category::factory()->create(['user_id' => $other->id]);
    $rule = Rule::factory()->create();

    $this->patchJson("/api/v1/rules/{$rule->id}", [
        'actions' => [
            ['type' => 'set_category', 'category_id' => $otherCategory->id],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('actions.0.category_id');

    $category = Category::factory()->create();

    $this->patchJson("/api/v1/rules/{$rule->id}", [
        'actions' => [
            ['type' => 'set_category', 'category_id' => $category->id],
        ],
    ])->assertOk()->assertJsonPath('data.actions.0.category_id', $category->id);
});

it('exclui uma regra', function () {
    actingAsUser();
    $rule = Rule::factory()->create();

    $this->deleteJson("/api/v1/rules/{$rule->id}")->assertNoContent();
    expect(Rule::count())->toBe(0);
});

it('reordena todas as regras do usuário', function () {
    actingAsUser();
    $a = Rule::factory()->create(['priority' => 0]);
    $b = Rule::factory()->create(['priority' => 1]);
    $c = Rule::factory()->create(['priority' => 2]);

    $this->putJson('/api/v1/rules/order', ['ids' => [$c->id, $a->id, $b->id]])
        ->assertNoContent();

    expect($a->refresh()->priority)->toBe(1);
    expect($b->refresh()->priority)->toBe(2);
    expect($c->refresh()->priority)->toBe(0);
});

it('422 ao reordenar com id faltando, repetido ou de outro usuário', function () {
    actingAsUser();
    $other = User::factory()->create();
    $a = Rule::factory()->create();
    $b = Rule::factory()->create();
    $otherRule = Rule::factory()->create(['user_id' => $other->id]);

    $this->putJson('/api/v1/rules/order', ['ids' => [$a->id]])
        ->assertStatus(422)->assertJsonValidationErrors('ids');

    $this->putJson('/api/v1/rules/order', ['ids' => [$a->id, $a->id]])
        ->assertStatus(422)->assertJsonValidationErrors('ids.1');

    $this->putJson('/api/v1/rules/order', ['ids' => [$a->id, $otherRule->id]])
        ->assertStatus(422)->assertJsonValidationErrors('ids');

    expect($b->refresh()->priority)->not->toBeNull();
});

it('404 para regra de outro usuário em GET/PATCH/DELETE e para id não numérico', function () {
    actingAsUser();
    $other = User::factory()->create();
    $rule = Rule::factory()->create(['user_id' => $other->id]);

    $this->getJson("/api/v1/rules/{$rule->id}")->assertNotFound();
    $this->patchJson("/api/v1/rules/{$rule->id}", ['name' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/rules/{$rule->id}")->assertNotFound();

    $this->getJson('/api/v1/rules/abc')->assertNotFound();
});
