<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

function previewPayload(array $overrides = []): array
{
    return array_merge([
        'match' => 'all',
        'conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => 'uber'],
        ],
        'actions' => [
            ['type' => 'set_category', 'category_id' => Category::factory()->create()->id],
        ],
    ], $overrides);
}

it('conta transações casadas e as que de fato mudariam', function () {
    actingAsUser();
    $target = Category::factory()->create();

    Transaction::factory()->create(['description' => 'Uber *trip 1', 'category_id' => null]);
    Transaction::factory()->create(['description' => 'Uber *trip 2', 'category_id' => $target->id]);
    Transaction::factory()->create(['description' => 'Padaria']);

    $response = $this->postJson('/api/v1/rules/preview', previewPayload([
        'actions' => [['type' => 'set_category', 'category_id' => $target->id]],
    ]))->assertOk();

    expect($response->json('data.matched'))->toBe(2);
    expect($response->json('data.changed'))->toBe(1);
});

it('categoria já preenchida não muda sem overwrite', function () {
    actingAsUser();
    $current = Category::factory()->create();
    $new = Category::factory()->create();

    Transaction::factory()->create([
        'description' => 'Uber *trip', 'category_id' => $current->id, 'categorized_by' => 'rule:1',
    ]);

    $response = $this->postJson('/api/v1/rules/preview', previewPayload([
        'actions' => [['type' => 'set_category', 'category_id' => $new->id]],
    ]))->assertOk();

    expect($response->json('data.matched'))->toBe(1);
    expect($response->json('data.changed'))->toBe(0);
});

it('com overwrite muda categoria de regra/histórico, mas não a definida à mão', function () {
    actingAsUser();
    $current = Category::factory()->create();
    $new = Category::factory()->create();

    $byRule = Transaction::factory()->create([
        'description' => 'Uber *trip 1', 'category_id' => $current->id, 'categorized_by' => 'rule:1',
    ]);
    $manual = Transaction::factory()->create([
        'description' => 'Uber *trip 2', 'category_id' => $current->id, 'categorized_by' => 'manual',
    ]);

    $response = $this->postJson('/api/v1/rules/preview', previewPayload([
        'actions' => [['type' => 'set_category', 'category_id' => $new->id]],
        'overwrite' => true,
    ]))->assertOk();

    expect($response->json('data.matched'))->toBe(2);
    expect($response->json('data.changed'))->toBe(1);

    $sampleIds = collect($response->json('data.sample'))->pluck('transaction.id')->all();
    expect($sampleIds)->toBe([$byRule->id]);
    expect($sampleIds)->not->toContain($manual->id);
});

it('amostra fica limitada a 20, só com as que mudam, mais recentes primeiro', function () {
    actingAsUser();
    $target = Category::factory()->create();

    $ids = [];
    for ($i = 0; $i < 25; $i++) {
        $ids[] = Transaction::factory()->create(['description' => "Uber *trip {$i}", 'category_id' => null])->id;
    }
    // Uma transação que casa mas não muda nada (mesma categoria já aplicada): não entra na amostra nem conta como changed.
    Transaction::factory()->create(['description' => 'Uber *trip extra', 'category_id' => $target->id]);

    $response = $this->postJson('/api/v1/rules/preview', previewPayload([
        'actions' => [['type' => 'set_category', 'category_id' => $target->id]],
    ]))->assertOk();

    expect($response->json('data.matched'))->toBe(26);
    expect($response->json('data.changed'))->toBe(25);

    $sample = $response->json('data.sample');
    expect($sample)->toHaveCount(20);

    $sampleIds = collect($sample)->pluck('transaction.id')->all();
    $expected = array_slice(array_reverse($ids), 0, 20);
    expect($sampleIds)->toBe($expected);
});

it('changes sempre tem as 5 chaves, mesmo quando só a categoria muda', function () {
    actingAsUser();
    $target = Category::factory()->create();
    Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => null]);

    $response = $this->postJson('/api/v1/rules/preview', previewPayload([
        'actions' => [['type' => 'set_category', 'category_id' => $target->id]],
    ]))->assertOk();

    expect($response->json('data.sample.0.changes'))->toBe([
        'category_id' => $target->id,
        'description' => null,
        'payee' => null,
        'tag_ids' => [],
        'is_ignored' => false,
    ]);
});

it('não grava nada no banco', function () {
    actingAsUser();
    $target = Category::factory()->create();
    $transaction = Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => null]);
    $updatedAt = $transaction->updated_at;
    $countBefore = Transaction::count();

    $this->postJson('/api/v1/rules/preview', previewPayload([
        'actions' => [['type' => 'set_category', 'category_id' => $target->id]],
    ]))->assertOk();

    $transaction->refresh();
    expect($transaction->category_id)->toBeNull();
    expect($transaction->updated_at->equalTo($updatedAt))->toBeTrue();
    expect(Transaction::count())->toBe($countBefore);
});

it('pernas de transferência ficam fora da prévia', function () {
    actingAsUser();
    $account = Account::factory()->create();
    $transferId = (string) Str::uuid();

    Transaction::factory()->create([
        'description' => 'Uber *trip', 'account_id' => $account->id, 'transfer_id' => $transferId,
    ]);
    Transaction::factory()->create([
        'description' => 'Uber *trip', 'account_id' => $account->id, 'transfer_id' => $transferId,
    ]);

    $response = $this->postJson('/api/v1/rules/preview', previewPayload())->assertOk();

    expect($response->json('data.matched'))->toBe(0);
});

it('a prévia só considera transações do usuário autenticado', function () {
    $other = User::factory()->create();
    actingAsUser();

    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    Transaction::factory()->create([
        'user_id' => $other->id, 'account_id' => $otherAccount->id, 'description' => 'Uber *trip',
    ]);

    $response = $this->postJson('/api/v1/rules/preview', previewPayload())->assertOk();

    expect($response->json('data.matched'))->toBe(0);
});

it('422 quando a definição da regra é inválida', function () {
    actingAsUser();

    $this->postJson('/api/v1/rules/preview', previewPayload([
        'conditions' => [
            ['field' => 'amount', 'op' => 'contains', 'value' => 100],
        ],
    ]))->assertStatus(422)->assertJsonValidationErrors('conditions.0.op');
});
