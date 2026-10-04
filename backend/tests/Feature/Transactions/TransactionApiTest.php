<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

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

it('confirma lançamento previsto de recorrência compatível em vez de criar outro', function () {
    actingAsUser();
    $account = Account::factory()->create();
    $category = Category::factory()->create();
    $recurrence = Recurrence::factory()->create([
        'account_id' => $account->id, 'category_id' => $category->id,
        'description' => 'Aluguel', 'amount' => 150000, 'direction' => 'out',
    ]);
    $prevista = Transaction::factory()->create([
        'account_id' => $account->id, 'status' => 'projected', 'source' => 'recurrence',
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-03-05', 'date' => '2026-03-05',
        'description' => 'Aluguel', 'original_description' => 'Aluguel',
        'amount' => 150000, 'direction' => 'out', 'category_id' => $category->id, 'categorized_by' => 'manual',
    ]);

    $response = $this->postJson('/api/v1/transactions', [
        'account_id' => $account->id, 'date' => '2026-03-07', 'amount' => 150000, 'direction' => 'out', 'description' => 'Aluguel',
    ])->assertCreated();

    expect(Transaction::count())->toBe(1)
        ->and($response->json('data.id'))->toBe($prevista->id)
        ->and($response->json('data.status'))->toBe('posted')
        ->and($response->json('data.date'))->toBe('2026-03-07')
        ->and($response->json('data.category.id'))->toBe($category->id)
        ->and($response->json('data.recurrence.id'))->toBe($recurrence->id)
        ->and($response->json('data.recurrence.description'))->toBe('Aluguel');

    $prevista->refresh();
    expect($prevista->recurrence_id)->toBe($recurrence->id)
        ->and($prevista->recurrence_date->toDateString())->toBe('2026-03-05')
        ->and($prevista->status->value)->toBe('posted');
});

it('sobrescreve a categoria da prevista quando o formulário informa uma, mas mantém a existente quando não informa', function () {
    actingAsUser();
    $account = Account::factory()->create();
    $originalCategory = Category::factory()->create();
    $newCategory = Category::factory()->create();
    $recurrence = Recurrence::factory()->create([
        'account_id' => $account->id, 'description' => 'Aluguel', 'amount' => 150000, 'direction' => 'out',
    ]);
    Transaction::factory()->create([
        'account_id' => $account->id, 'status' => 'projected', 'source' => 'recurrence',
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-03-05', 'date' => '2026-03-05',
        'description' => 'Aluguel', 'original_description' => 'Aluguel',
        'amount' => 150000, 'direction' => 'out', 'category_id' => $originalCategory->id, 'categorized_by' => 'manual',
    ]);

    $this->postJson('/api/v1/transactions', [
        'account_id' => $account->id, 'date' => '2026-03-07', 'amount' => 150000, 'direction' => 'out',
        'description' => 'Aluguel', 'category_id' => $newCategory->id,
    ])->assertCreated()->assertJsonPath('data.category.id', $newCategory->id);
});

it('sem prevista compatível, cria uma transação normalmente e deixa a prevista intacta', function () {
    actingAsUser();
    $account = Account::factory()->create();
    $recurrence = Recurrence::factory()->create([
        'account_id' => $account->id, 'description' => 'Aluguel', 'amount' => 150000, 'direction' => 'out',
    ]);
    Transaction::factory()->create([
        'account_id' => $account->id, 'status' => 'projected', 'source' => 'recurrence',
        'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-03-05', 'date' => '2026-03-05',
        'description' => 'Aluguel', 'original_description' => 'Aluguel',
        'amount' => 150000, 'direction' => 'out',
    ]);

    $this->postJson('/api/v1/transactions', [
        'account_id' => $account->id, 'date' => '2026-05-01', 'amount' => 150000, 'direction' => 'out', 'description' => 'Aluguel',
    ])->assertCreated()->assertJsonMissingPath('data.recurrence');

    expect(Transaction::count())->toBe(2)
        ->and(Transaction::where('status', 'projected')->count())->toBe(1);
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

it('recusa amount acima do limite máximo', function () {
    actingAsUser();
    $account = Account::factory()->create();

    $this->postJson('/api/v1/transactions', [
        'account_id' => $account->id, 'date' => '2026-10-01', 'amount' => 1_000_000_000_000_001, 'direction' => 'out', 'description' => 'x',
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

it('normaliza amount enviado como string ou float numérico', function () {
    actingAsUser();
    $tx = Transaction::factory()->create(['amount' => 1000]);

    $this->patchJson("/api/v1/transactions/{$tx->id}", ['amount' => '4590'])
        ->assertOk()
        ->assertJsonPath('data.amount', 4590);

    $this->patchJson("/api/v1/transactions/{$tx->id}", ['amount' => 4590.0])
        ->assertOk()
        ->assertJsonPath('data.amount', 4590);
});

it('perna de transferência só trava quando um campo bloqueado de fato muda', function () {
    actingAsUser();
    $transferId = (string) Str::uuid();
    $leg = Transaction::factory()->create([
        'transfer_id' => $transferId,
        'amount' => 2000,
        'date' => '2026-10-01',
        'direction' => 'out',
    ]);

    // PATCH com o objeto inteiro, mas sem mudar os campos bloqueados: permitido.
    $this->patchJson("/api/v1/transactions/{$leg->id}", [
        'account_id' => $leg->account_id,
        'date' => '2026-10-01',
        'amount' => 2000,
        'direction' => 'out',
        'notes' => 'ajuste',
    ])->assertOk()->assertJsonPath('data.notes', 'ajuste');

    $this->patchJson("/api/v1/transactions/{$leg->id}", ['amount' => 3000])
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_leg_locked');
});

it('bloqueia ignorar uma perna de transferência pelo endpoint de transações', function () {
    actingAsUser();
    $leg = Transaction::factory()->create([
        'transfer_id' => (string) Str::uuid(),
        'is_ignored' => false,
    ]);

    $this->patchJson("/api/v1/transactions/{$leg->id}", ['is_ignored' => true])
        ->assertStatus(409)
        ->assertJsonPath('code', 'transfer_leg_locked');

    $this->patchJson("/api/v1/transactions/{$leg->id}", ['is_ignored' => false])
        ->assertOk()
        ->assertJsonPath('data.is_ignored', false);
});

it('não move transação para conta com moeda diferente', function () {
    actingAsUser();
    $tx = Transaction::factory()->create(['currency' => 'BRL']);
    $usdAccount = Account::factory()->create(['currency' => 'USD']);

    $this->patchJson("/api/v1/transactions/{$tx->id}", ['account_id' => $usdAccount->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'transaction_currency_mismatch');
});

it('valida que account_id, category_id e tag_ids pertencem ao usuário ao editar', function () {
    $other = User::factory()->create();
    $foreignAccount = Account::factory()->create(['user_id' => $other->id]);
    $foreignCategory = Category::factory()->create(['user_id' => $other->id]);
    $foreignTag = Tag::factory()->create(['user_id' => $other->id]);

    actingAsUser();
    $tx = Transaction::factory()->create();

    $this->patchJson("/api/v1/transactions/{$tx->id}", [
        'account_id' => $foreignAccount->id,
        'category_id' => $foreignCategory->id,
        'tag_ids' => [$foreignTag->id],
    ])->assertStatus(422)->assertJsonValidationErrors(['account_id', 'category_id', 'tag_ids.0']);
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
    $this->patchJson("/api/v1/transactions/{$foreign->id}", ['notes' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/transactions/{$foreign->id}")->assertNotFound();
});

it('não exclui conta com transações', function () {
    actingAsUser();
    $tx = Transaction::factory()->create();

    $this->deleteJson("/api/v1/accounts/{$tx->account_id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'account_has_transactions');
});

it('decompõe categorized_by em categorization (source e rule_id)', function (?string $categorizedBy, bool $withCategory, ?array $expected) {
    actingAsUser();
    $category = $withCategory ? Category::factory()->create() : null;
    $tx = Transaction::factory()->create(['category_id' => $category?->id, 'categorized_by' => $categorizedBy]);

    $this->getJson("/api/v1/transactions/{$tx->id}")
        ->assertOk()
        ->assertJsonPath('data.categorization', $expected);
})->with([
    'manual' => ['manual', true, ['source' => 'manual']],
    'history' => ['history', true, ['source' => 'history']],
    'pluggy' => ['pluggy', true, ['source' => 'pluggy']],
    'rule:N' => ['rule:42', true, ['source' => 'rule', 'rule_id' => 42]],
    'rule malformada (zero à esquerda)' => ['rule:007', true, null],
    'lixo' => ['qualquer-coisa', true, null],
    'sem categoria, mesmo com categorized_by preenchido' => ['manual', false, null],
    'sem categoria e sem categorized_by' => [null, false, null],
]);
