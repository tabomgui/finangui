<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Actions\CategorizeTransaction;
use App\Domain\Rules\Models\Rule;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

it('uma regra ativa com set_category categoriza o lançamento novo, mas não aplica as outras ações', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $tag = Tag::factory()->create(['user_id' => $this->user->id]);

    $rule = Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => 'uber'],
        ],
        'actions' => [
            ['type' => 'set_category', 'category_id' => $category->id],
            ['type' => 'set_description', 'value' => 'Uber'],
            ['type' => 'add_tag', 'tag_id' => $tag->id],
            ['type' => 'ignore'],
        ],
    ]);

    $response = $this->postJson('/api/v1/transactions', [
        'account_id' => $this->account->id,
        'date' => '2026-10-01',
        'amount' => 1000,
        'direction' => 'out',
        'description' => 'Uber *trip 99',
    ])->assertCreated();

    $response->assertJsonPath('data.category_id', $category->id)
        ->assertJsonPath('data.categorized_by', "rule:{$rule->id}")
        ->assertJsonPath('data.description', 'Uber *trip 99')
        ->assertJsonPath('data.is_ignored', false)
        ->assertJsonPath('data.tags', []);
});

it('regra inativa não conta e prioridade menor vence', function () {
    $categoryA = Category::factory()->create(['user_id' => $this->user->id]);
    $categoryB = Category::factory()->create(['user_id' => $this->user->id]);
    $categoryInactive = Category::factory()->create(['user_id' => $this->user->id]);

    Rule::factory()->create([
        'user_id' => $this->user->id,
        'is_active' => false,
        'priority' => 0,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $categoryInactive->id]],
    ]);
    Rule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 2,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $categoryB->id]],
    ]);
    $winner = Rule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $categoryA->id]],
    ]);

    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->account->id, 'date' => '2026-10-01', 'amount' => 1000, 'direction' => 'out', 'description' => 'Uber trip',
    ])->assertCreated()
        ->assertJsonPath('data.category_id', $categoryA->id)
        ->assertJsonPath('data.categorized_by', "rule:{$winner->id}");
});

it('sem regra, o histórico categoriza pela categoria mais frequente', function () {
    $categoryA = Category::factory()->create(['user_id' => $this->user->id]);
    $categoryB = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->count(2)->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'description' => 'Uber *trip 1', 'direction' => 'out', 'category_id' => $categoryA->id,
    ]);
    Transaction::factory()->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'description' => 'Uber *trip 1', 'direction' => 'out', 'category_id' => $categoryB->id,
    ]);

    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->account->id, 'date' => '2026-10-01', 'amount' => 1000, 'direction' => 'out', 'description' => 'UBER TRIP 99',
    ])->assertCreated()
        ->assertJsonPath('data.category_id', $categoryA->id)
        ->assertJsonPath('data.categorized_by', 'history');
});

it('categoria informada pelo usuário nunca é trocada', function () {
    $manual = Category::factory()->create(['user_id' => $this->user->id]);
    $ruleCategory = Category::factory()->create(['user_id' => $this->user->id]);

    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $ruleCategory->id]],
    ]);

    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->account->id, 'date' => '2026-10-01', 'amount' => 1000, 'direction' => 'out',
        'description' => 'Uber trip', 'category_id' => $manual->id,
    ])->assertCreated()
        ->assertJsonPath('data.category_id', $manual->id)
        ->assertJsonPath('data.categorized_by', 'manual');
});

it('compra parcelada sem categoria: todas as parcelas recebem a mesma categoria e categorized_by', function () {
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'notebook']],
        'actions' => [['type' => 'set_category', 'category_id' => $category->id]],
    ]);

    $this->postJson('/api/v1/transactions', [
        'account_id' => $card->id, 'date' => '2026-10-01', 'amount' => 3000, 'direction' => 'out',
        'description' => 'Notebook', 'installments' => 3,
    ])->assertCreated();

    expect(Transaction::count())->toBe(3);

    Transaction::query()->get()->each(function (Transaction $t) use ($category) {
        expect($t->category_id)->toBe($category->id);
        $ruleId = Rule::query()->first()?->id;
        expect($t->categorized_by)->toBe("rule:{$ruleId}");
    });
});

it('transferência nunca é categorizada', function () {
    $other = Account::factory()->create(['user_id' => $this->user->id]);
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'transferencia']],
        'actions' => [['type' => 'set_category', 'category_id' => $category->id]],
    ]);

    $this->postJson('/api/v1/transfers', [
        'from_account_id' => $this->account->id,
        'to_account_id' => $other->id,
        'date' => '2026-10-01',
        'amount' => 1000,
        'description' => 'Transferencia interna',
    ])->assertCreated();

    expect(Transaction::count())->toBe(2);

    Transaction::query()->get()->each(function (Transaction $t) {
        expect($t->category_id)->toBeNull();
        expect($t->categorized_by)->toBeNull();
    });
});

it('CategorizeTransaction::handle não toca em perna de transferência, mesmo com regra e histórico batendo', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'pix']],
        'actions' => [['type' => 'set_category', 'category_id' => $category->id]],
    ]);

    $leg = Transaction::factory()->make([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'description' => 'Pix recebido',
        'direction' => 'in',
        'category_id' => null,
        'transfer_id' => (string) Str::uuid(),
    ]);

    app(CategorizeTransaction::class)->handle($leg);

    expect($leg->category_id)->toBeNull();
    expect($leg->categorized_by)->toBeNull();
});

it('categoria arquivada não é usada pela regra na categorização do lançamento', function () {
    $archived = Category::factory()->create(['user_id' => $this->user->id, 'is_archived' => true]);
    $history = Category::factory()->create(['user_id' => $this->user->id]);

    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $archived->id]],
    ]);

    Transaction::factory()->count(2)->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'description' => 'Uber trip 1', 'direction' => 'out', 'category_id' => $history->id,
    ]);

    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->account->id, 'date' => '2026-10-01', 'amount' => 1000, 'direction' => 'out', 'description' => 'Uber trip 99',
    ])->assertCreated()
        ->assertJsonPath('data.category_id', $history->id)
        ->assertJsonPath('data.categorized_by', 'history');
});

it('regra cuja categoria foi excluída cai para o histórico', function () {
    $ruleCategory = Category::factory()->create(['user_id' => $this->user->id]);
    $history = Category::factory()->create(['user_id' => $this->user->id]);

    Rule::factory()->create([
        'user_id' => $this->user->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $ruleCategory->id]],
    ]);

    Transaction::factory()->count(2)->create([
        'account_id' => $this->account->id, 'user_id' => $this->user->id,
        'description' => 'Uber trip 1', 'direction' => 'out', 'category_id' => $history->id,
    ]);

    $ruleCategory->delete();

    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->account->id, 'date' => '2026-10-01', 'amount' => 1000, 'direction' => 'out', 'description' => 'Uber trip 99',
    ])->assertCreated()
        ->assertJsonPath('data.category_id', $history->id)
        ->assertJsonPath('data.categorized_by', 'history');
});

it('regra casa por uma condição dentro de um grupo', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $rule = Rule::factory()->create([
        'user_id' => $this->user->id,
        'match' => 'all',
        'conditions' => [
            [
                'match' => 'any',
                'conditions' => [
                    ['field' => 'description', 'op' => 'contains', 'value' => 'uber'],
                    ['field' => 'description', 'op' => 'contains', 'value' => 'ifood'],
                ],
            ],
        ],
        'actions' => [['type' => 'set_category', 'category_id' => $category->id]],
    ]);

    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->account->id, 'date' => '2026-10-01', 'amount' => 1000, 'direction' => 'out', 'description' => 'iFood pedido',
    ])->assertCreated()
        ->assertJsonPath('data.category_id', $category->id)
        ->assertJsonPath('data.categorized_by', "rule:{$rule->id}");
});
