<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;

it('cria despesa manual', function () {
    actingAsUser();
    $account = Account::factory()->create();
    $category = Category::factory()->create();
    $tag = Tag::factory()->create();

    $this->postJson('/api/v1/transactions', [
        'account_id' => $account->id,
        'date' => '2026-10-01',
        'amount' => 4590,
        'direction' => 'out',
        'description' => 'Padaria',
        'category_id' => $category->id,
        'tag_ids' => [$tag->id],
        'notes' => 'café da manhã',
    ])->assertCreated()
        ->assertJsonPath('data.amount', 4590)
        ->assertJsonPath('data.direction', 'out')
        ->assertJsonPath('data.date', '2026-10-01')
        ->assertJsonPath('data.currency', 'BRL')
        ->assertJsonPath('data.description', 'Padaria')
        ->assertJsonPath('data.original_description', 'Padaria')
        ->assertJsonPath('data.status', 'posted')
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.categorized_by', 'manual')
        ->assertJsonPath('data.account.id', $account->id)
        ->assertJsonPath('data.category.id', $category->id)
        ->assertJsonPath('data.tags.0.id', $tag->id);
});

it('cria transação sem categoria com categorized_by nulo', function () {
    actingAsUser();
    $account = Account::factory()->create();

    $this->postJson('/api/v1/transactions', [
        'account_id' => $account->id, 'date' => '2026-10-01', 'amount' => 100, 'direction' => 'in', 'description' => 'Pix',
    ])->assertCreated()->assertJsonPath('data.categorized_by', null);
});

it('valida valor, sentido e propriedade da conta', function () {
    $other = User::factory()->create();
    $foreign = Account::factory()->create(['user_id' => $other->id]);

    actingAsUser();

    $this->postJson('/api/v1/transactions', [
        'account_id' => $foreign->id, 'date' => '01/10/2026', 'amount' => 10.5, 'direction' => 'sideways', 'description' => '',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['account_id', 'date', 'amount', 'direction', 'description']);

    $own = Account::factory()->create();
    $this->postJson('/api/v1/transactions', [
        'account_id' => $own->id, 'date' => '2026-10-01', 'amount' => 0, 'direction' => 'out', 'description' => 'x',
    ])->assertStatus(422)->assertJsonValidationErrors('amount');
});

it('editar a descrição trava contra regras e mantém a original', function () {
    actingAsUser();
    $tx = Transaction::factory()->create(['description' => 'PAG*IFOOD', 'original_description' => 'PAG*IFOOD']);

    $this->patchJson("/api/v1/transactions/{$tx->id}", ['description' => 'iFood'])
        ->assertOk()
        ->assertJsonPath('data.description', 'iFood')
        ->assertJsonPath('data.original_description', 'PAG*IFOOD')
        ->assertJsonPath('data.description_locked', true);
});

it('trocar a categoria marca categorized_by como manual', function () {
    actingAsUser();
    $tx = Transaction::factory()->create(['category_id' => null, 'categorized_by' => null]);
    $category = Category::factory()->create();

    $this->patchJson("/api/v1/transactions/{$tx->id}", ['category_id' => $category->id])
        ->assertOk()
        ->assertJsonPath('data.categorized_by', 'manual');
});

it('sincroniza tags na edição', function () {
    actingAsUser();
    $tx = Transaction::factory()->create();
    [$a, $b] = Tag::factory()->count(2)->create();
    $tx->tags()->sync([$a->id]);

    $this->patchJson("/api/v1/transactions/{$tx->id}", ['tag_ids' => [$b->id]])
        ->assertOk()
        ->assertJsonCount(1, 'data.tags')
        ->assertJsonPath('data.tags.0.id', $b->id);
});

it('exclui transação', function () {
    actingAsUser();
    $tx = Transaction::factory()->create();

    $this->deleteJson("/api/v1/transactions/{$tx->id}")->assertNoContent();

    expect(Transaction::count())->toBe(0);
});

it('retorna 404 para transação de outro usuário', function () {
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $other->id]);
    $foreign = Transaction::factory()->create(['account_id' => $account->id]);

    actingAsUser();

    $this->getJson("/api/v1/transactions/{$foreign->id}")->assertNotFound();
    $this->deleteJson("/api/v1/transactions/{$foreign->id}")->assertNotFound();
});

it('não exclui conta com transações', function () {
    actingAsUser();
    $tx = Transaction::factory()->create();

    $this->deleteJson("/api/v1/accounts/{$tx->account_id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'account_has_transactions');
});
