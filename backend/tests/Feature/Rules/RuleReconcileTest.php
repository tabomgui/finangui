<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Categories\Models\Category;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Rules\Models\Rule;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Regra "Transporte" já salva (como se tivesse acabado de ser editada):
 * descrição contém "uber" -> $category.
 */
function reconcileRule(Category $category, array $overrides = []): Rule
{
    return Rule::factory()->create(array_merge([
        'name' => 'Transporte',
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $category->id]],
    ], $overrides));
}

/**
 * @return array<string, array<string, mixed>>
 */
function reconcileGroups(array $response): array
{
    return collect($response['data']['groups'])->keyBy('key')->all();
}

/**
 * @return list<int>
 */
function reconcileSampleIds(array $group): array
{
    return collect($group['sample'])->pluck('transaction_id')->all();
}

it('separa em três grupos o que a regra editada mudaria', function () {
    actingAsUser();
    $old = Category::factory()->create();
    $new = Category::factory()->create();
    $rule = reconcileRule($new);
    $tag = "rule:{$rule->id}";

    $recategorize = Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => $old->id, 'categorized_by' => $tag]);
    $fill = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);
    $clear = Transaction::factory()->create(['description' => 'Padaria Central', 'category_id' => $old->id, 'categorized_by' => $tag]);
    // Nada a fazer: já na categoria da regra, à mão, ou categorizado por outro caminho.
    Transaction::factory()->create(['description' => 'Uber *x', 'category_id' => $new->id, 'categorized_by' => $tag]);
    Transaction::factory()->create(['description' => 'Uber *y', 'category_id' => $old->id, 'categorized_by' => 'manual']);
    Transaction::factory()->create(['description' => 'Uber *z', 'category_id' => $old->id, 'categorized_by' => 'history']);
    Transaction::factory()->create(['description' => 'Padaria Sul', 'category_id' => $old->id, 'categorized_by' => 'rule:999999']);

    $response = $this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json();
    $groups = reconcileGroups($response);

    expect(array_keys($groups))->toBe(['recategorize', 'fill', 'clear']);
    expect($response['data']['max_changes'])->toBe(5000);

    expect($groups['recategorize']['count'])->toBe(1);
    expect($groups['recategorize']['sample'][0])->toMatchArray([
        'transaction_id' => $recategorize->id,
        'current_category_id' => $old->id,
        'new_category_id' => $new->id,
    ]);

    expect($groups['fill']['count'])->toBe(1);
    expect(reconcileSampleIds($groups['fill']))->toBe([$fill->id]);
    expect($groups['fill']['sample'][0]['new_category_id'])->toBe($new->id);
    expect($groups['fill']['sample'][0])->not->toHaveKey('current_category_id');

    expect($groups['clear']['count'])->toBe(1);
    expect($groups['clear']['reason'])->toBe('no_longer_matches');
    expect(reconcileSampleIds($groups['clear']))->toBe([$clear->id]);
    expect($groups['clear']['sample'][0]['current_category_id'])->toBe($old->id);
    expect($groups['clear']['sample'][0])->not->toHaveKey('new_category_id');

    expect($groups['recategorize'])->not->toHaveKey('reason');
    expect($groups['fill'])->not->toHaveKey('reason');
});

it('a amostra traz os dados do lançamento para exibir', function () {
    actingAsUser();
    $new = Category::factory()->create();
    $rule = reconcileRule($new);
    $account = Account::factory()->create(['name' => 'Conta Exemplo']);
    $transaction = Transaction::factory()->for($account)->create([
        'description' => 'Uber eats', 'category_id' => null, 'amount' => 1990, 'date' => '2026-03-04',
    ]);

    $groups = reconcileGroups($this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json());

    expect($groups['fill']['sample'][0])->toBe([
        'transaction_id' => $transaction->id,
        'date' => '2026-03-04',
        'description' => 'Uber eats',
        'account_name' => 'Conta Exemplo',
        'amount' => 1990,
        'direction' => 'out',
        'new_category_id' => $new->id,
    ]);
});

it('regra sem set_category: os categorizados por ela perdem a categoria, motivo próprio', function () {
    actingAsUser();
    $old = Category::factory()->create();
    $rule = reconcileRule($old, ['actions' => [['type' => 'set_payee', 'value' => 'Uber BV']]]);

    $matches = Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => $old->id, 'categorized_by' => "rule:{$rule->id}"]);
    $notMatching = Transaction::factory()->create(['description' => 'Padaria', 'category_id' => $old->id, 'categorized_by' => "rule:{$rule->id}"]);
    Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);

    $groups = reconcileGroups($this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json());

    expect($groups['recategorize']['count'])->toBe(0);
    expect($groups['fill']['count'])->toBe(0);
    expect($groups['clear']['count'])->toBe(2);
    expect($groups['clear']['reason'])->toBe('no_category_action');
    expect(reconcileSampleIds($groups['clear']))->toEqualCanonicalizing([$matches->id, $notMatching->id]);
});

it('avalia a regra mesmo desativada', function () {
    actingAsUser();
    $new = Category::factory()->create();
    $rule = reconcileRule($new, ['is_active' => false]);
    Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);

    $groups = reconcileGroups($this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json());

    expect($groups['fill']['count'])->toBe(1);
});

it('deixa de fora perna de transferência, prevista de recorrência e pagamento de fatura', function () {
    actingAsUser();
    $old = Category::factory()->create();
    $new = Category::factory()->create();
    $rule = reconcileRule($new);
    $account = Account::factory()->create();

    Transaction::factory()->for($account)->create(['description' => 'Uber transferência', 'transfer_id' => (string) Str::uuid()]);
    $recurrence = Recurrence::factory()->create(['account_id' => $account->id, 'description' => 'Uber mensal']);
    Transaction::factory()->for($account)->create([
        'description' => 'Uber mensal', 'status' => 'projected', 'recurrence_id' => $recurrence->id, 'recurrence_date' => '2026-01-05',
    ]);
    $statement = CardStatement::factory()->create();
    Transaction::factory()->create([
        'account_id' => $statement->account_id, 'description' => 'Uber pagamento', 'direction' => 'in',
        'statement_id' => $statement->id, 'card_payment_statement_id' => $statement->id,
        'category_id' => $old->id, 'categorized_by' => "rule:{$rule->id}",
    ]);

    $groups = reconcileGroups($this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json());

    expect(collect($groups)->sum('count'))->toBe(0);
});

it('nunca oferece tirar uma categoria de transferência', function () {
    actingAsUser();
    $transfer = Category::factory()->transfer()->create();
    $new = Category::factory()->create();
    $rule = reconcileRule($new);
    Transaction::factory()->create(['description' => 'Aplicação CDB', 'category_id' => $transfer->id, 'categorized_by' => "rule:{$rule->id}"]);

    $groups = reconcileGroups($this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json());

    expect($groups['clear']['count'])->toBe(0);
});

it('categoria da regra arquivada: nada muda', function () {
    actingAsUser();
    $old = Category::factory()->create();
    $archived = Category::factory()->create(['is_archived' => true]);
    $rule = reconcileRule($archived);
    Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => $old->id, 'categorized_by' => "rule:{$rule->id}"]);
    Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);

    $groups = reconcileGroups($this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json());

    expect(collect($groups)->sum('count'))->toBe(0);
});

it('amostra limitada a 20, mais recentes primeiro, com a contagem completa', function () {
    actingAsUser();
    $rule = reconcileRule(Category::factory()->create());
    $account = Account::factory()->create();

    $ids = [];
    for ($i = 0; $i < 22; $i++) {
        $ids[] = Transaction::factory()->for($account)->create([
            'description' => "Uber {$i}", 'category_id' => null, 'date' => now()->subDays(30 - $i)->toDateString(),
        ])->id;
    }

    $groups = reconcileGroups($this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json());

    expect($groups['fill']['count'])->toBe(22);
    expect(reconcileSampleIds($groups['fill']))->toBe(array_slice(array_reverse($ids), 0, 20));
});

it('a prévia da reconciliação não grava nada', function () {
    actingAsUser();
    $old = Category::factory()->create();
    $rule = reconcileRule(Category::factory()->create());
    $transaction = Transaction::factory()->create(['description' => 'Padaria', 'category_id' => $old->id, 'categorized_by' => "rule:{$rule->id}"]);

    $this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk();

    expect($transaction->refresh()->category_id)->toBe($old->id);
});

it('aplica só os grupos escolhidos', function () {
    actingAsUser();
    $old = Category::factory()->create();
    $new = Category::factory()->create();
    $rule = reconcileRule($new);
    $tag = "rule:{$rule->id}";

    $recategorize = Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => $old->id, 'categorized_by' => $tag]);
    $fill = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);
    $clear = Transaction::factory()->create(['description' => 'Padaria Central', 'category_id' => $old->id, 'categorized_by' => $tag]);
    $descriptionKey = $clear->description_key;

    $response = $this->postJson("/api/v1/rules/{$rule->id}/reconcile", ['groups' => ['recategorize', 'clear']])->assertOk();

    expect($response->json('data'))->toBe(['recategorize' => 1, 'fill' => 0, 'clear' => 1, 'skipped' => 0]);

    $recategorize->refresh();
    expect($recategorize->category_id)->toBe($new->id);
    expect($recategorize->categorized_by)->toBe($tag);

    expect($fill->refresh()->category_id)->toBeNull();

    $clear->refresh();
    expect($clear->category_id)->toBeNull();
    expect($clear->categorized_by)->toBeNull();
    expect($clear->description_key)->toBe($descriptionKey);
});

it('preencher grava categorized_by da regra e nunca toca no que foi feito à mão', function () {
    actingAsUser();
    $old = Category::factory()->create();
    $new = Category::factory()->create();
    $rule = reconcileRule($new);

    $fill = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);
    $manual = Transaction::factory()->create(['description' => 'Uber *y', 'category_id' => $old->id, 'categorized_by' => 'manual']);

    $this->postJson("/api/v1/rules/{$rule->id}/reconcile", ['groups' => ['recategorize', 'fill', 'clear']])
        ->assertOk()
        ->assertJsonPath('data.fill', 1);

    $fill->refresh();
    expect($fill->category_id)->toBe($new->id);
    expect($fill->categorized_by)->toBe("rule:{$rule->id}");

    $manual->refresh();
    expect($manual->category_id)->toBe($old->id);
    expect($manual->categorized_by)->toBe('manual');
});

it('recalcula na hora de aplicar: quem mudou depois da prévia não entra', function () {
    actingAsUser();
    $other = Category::factory()->create();
    $new = Category::factory()->create();
    $rule = reconcileRule($new);
    $transaction = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);

    $groups = reconcileGroups($this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json());
    expect($groups['fill']['count'])->toBe(1);

    // Categorizado à mão entre a prévia e a confirmação.
    $transaction->update(['category_id' => $other->id, 'categorized_by' => 'manual']);

    $this->postJson("/api/v1/rules/{$rule->id}/reconcile", ['groups' => ['fill']])
        ->assertOk()
        ->assertJsonPath('data.fill', 0);

    expect($transaction->refresh()->category_id)->toBe($other->id);
});

it('usa a definição salva no momento de aplicar', function () {
    actingAsUser();
    $first = Category::factory()->create();
    $second = Category::factory()->create();
    $rule = reconcileRule($first);
    $transaction = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);

    $rule->update(['actions' => [['type' => 'set_category', 'category_id' => $second->id]]]);

    $this->postJson("/api/v1/rules/{$rule->id}/reconcile", ['groups' => ['fill']])->assertOk();

    expect($transaction->refresh()->category_id)->toBe($second->id);
});

it('409 acima de 5000 alterações, sem gravar nada', function () {
    $user = actingAsUser();
    $new = Category::factory()->create();
    $rule = reconcileRule($new);
    $account = Account::factory()->create();

    insertBulkTransactions($user, $account, 5001, ['description' => 'Uber eats']);

    $this->postJson("/api/v1/rules/{$rule->id}/reconcile", ['groups' => ['fill']])
        ->assertStatus(409)
        ->assertJsonPath('code', 'too_many_category_changes');

    expect(Transaction::query()->whereNotNull('category_id')->count())->toBe(0);
});

it('422 com grupo desconhecido ou lista vazia', function () {
    actingAsUser();
    $rule = reconcileRule(Category::factory()->create());

    $this->postJson("/api/v1/rules/{$rule->id}/reconcile", ['groups' => []])
        ->assertStatus(422)->assertJsonValidationErrors('groups');
    $this->postJson("/api/v1/rules/{$rule->id}/reconcile", ['groups' => ['stale']])
        ->assertStatus(422)->assertJsonValidationErrors('groups.0');
});

it('regra de outro usuário: 404, e lançamentos de outro usuário nunca entram', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $otherCategory = Category::factory()->create(['user_id' => $other->id]);
    $otherRule = Rule::factory()->create([
        'user_id' => $other->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $otherCategory->id]],
    ]);
    $otherTransaction = Transaction::factory()->create([
        'user_id' => $other->id, 'account_id' => $otherAccount->id, 'description' => 'Uber eats', 'category_id' => null,
    ]);

    actingAsUser();
    $rule = reconcileRule(Category::factory()->create());

    $this->getJson("/api/v1/rules/{$otherRule->id}/reconcile")->assertNotFound();
    $this->postJson("/api/v1/rules/{$otherRule->id}/reconcile", ['groups' => ['fill']])->assertNotFound();

    $groups = reconcileGroups($this->getJson("/api/v1/rules/{$rule->id}/reconcile")->assertOk()->json());
    expect($groups['fill']['count'])->toBe(0);

    $this->postJson("/api/v1/rules/{$rule->id}/reconcile", ['groups' => ['fill']])->assertOk();
    expect(Transaction::query()->withoutGlobalScopes()->find($otherTransaction->id)->category_id)->toBeNull();
});
