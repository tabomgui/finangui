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
 * Regra ativa "descrição contém $term" -> $category.
 */
function reviewRule(string $term, Category $category, array $overrides = []): Rule
{
    return Rule::factory()->create(array_merge([
        'name' => ucfirst($term),
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => $term]],
        'actions' => [['type' => 'set_category', 'category_id' => $category->id]],
    ], $overrides));
}

/**
 * @return array<string, array<string, mixed>>
 */
function reviewGroups(): array
{
    return collect(test()->getJson('/api/v1/rules/review')->assertOk()->json('data.groups'))->keyBy('key')->all();
}

/**
 * @return array<int, array<string, mixed>>
 */
function reviewItemsById(array $group): array
{
    return collect($group['items'])->keyBy('transaction_id')->all();
}

it('agrupa as diferenças entre as regras ativas e a categoria atual', function () {
    actingAsUser();
    $transport = Category::factory()->create();
    $food = Category::factory()->create();
    $old = Category::factory()->create();
    $rule = reviewRule('uber', $transport);

    $fill = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);
    $fromHistory = Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => $food->id, 'categorized_by' => 'history']);
    $fromBank = Transaction::factory()->create(['description' => 'Uber *bank', 'category_id' => $food->id, 'categorized_by' => 'pluggy']);
    $fromOtherRule = Transaction::factory()->create(['description' => 'Uber *rule', 'category_id' => $food->id, 'categorized_by' => 'rule:999999']);
    $manual = Transaction::factory()->create(['description' => 'Uber *mão', 'category_id' => $food->id, 'categorized_by' => 'manual']);
    // Nada a fazer: já na categoria das regras, sem regra que case, ou sem origem conhecida.
    Transaction::factory()->create(['description' => 'Uber *ok', 'category_id' => $transport->id, 'categorized_by' => 'history']);
    Transaction::factory()->create(['description' => 'Padaria', 'category_id' => null]);
    Transaction::factory()->create(['description' => 'Padaria', 'category_id' => $old->id, 'categorized_by' => 'history']);

    $groups = reviewGroups();

    expect(array_keys($groups))->toBe(['fill', 'conflict', 'stale', 'manual']);

    expect($groups['fill']['count'])->toBe(1);
    expect($groups['fill']['applicable'])->toBeTrue();
    expect($groups['fill']['truncated'])->toBeFalse();
    expect($groups['fill']['items'][0])->toMatchArray([
        'transaction_id' => $fill->id,
        'new_category_id' => $transport->id,
        'rule_id' => $rule->id,
        'rule_name' => 'Uber',
    ]);
    expect($groups['fill']['items'][0])->not->toHaveKey('current_category_id');

    expect($groups['conflict']['count'])->toBe(3);
    expect(array_keys(reviewItemsById($groups['conflict'])))->toEqualCanonicalizing([$fromHistory->id, $fromBank->id, $fromOtherRule->id]);
    expect(reviewItemsById($groups['conflict'])[$fromHistory->id])->toMatchArray([
        'current_category_id' => $food->id, 'new_category_id' => $transport->id, 'rule_id' => $rule->id,
    ]);

    expect($groups['stale']['count'])->toBe(0);

    expect($groups['manual']['count'])->toBe(1);
    expect($groups['manual']['applicable'])->toBeFalse();
    expect($groups['manual']['items'][0])->toMatchArray([
        'transaction_id' => $manual->id, 'current_category_id' => $food->id, 'new_category_id' => $transport->id,
    ]);
});

it('stale: regra excluída, desativada, que não casa mais ou que não define mais categoria', function () {
    actingAsUser();
    $old = Category::factory()->create();

    $inactive = reviewRule('cinema', Category::factory()->create(), ['name' => 'Cinema', 'is_active' => false]);
    $noLongerMatches = reviewRule('mercado', Category::factory()->create(), ['name' => 'Mercado']);
    $noCategory = reviewRule('farmácia', $old, ['name' => 'Farmácia', 'actions' => [['type' => 'set_payee', 'value' => 'Drogaria']]]);

    $deleted = Transaction::factory()->create(['description' => 'Academia', 'category_id' => $old->id, 'categorized_by' => 'rule:999999']);
    $byInactive = Transaction::factory()->create(['description' => 'Cinema Centro', 'category_id' => $old->id, 'categorized_by' => "rule:{$inactive->id}"]);
    $byNoLongerMatches = Transaction::factory()->create(['description' => 'Feira livre', 'category_id' => $old->id, 'categorized_by' => "rule:{$noLongerMatches->id}"]);
    $byNoCategory = Transaction::factory()->create(['description' => 'Farmácia Sul', 'category_id' => $old->id, 'categorized_by' => "rule:{$noCategory->id}"]);
    // Histórico nunca fica "stale": só o que uma regra categorizou.
    Transaction::factory()->create(['description' => 'Academia', 'category_id' => $old->id, 'categorized_by' => 'history']);

    $groups = reviewGroups();
    $items = reviewItemsById($groups['stale']);

    expect($groups['stale']['count'])->toBe(4);
    expect(array_keys($items))->toEqualCanonicalizing([$deleted->id, $byInactive->id, $byNoLongerMatches->id, $byNoCategory->id]);
    expect($items[$byInactive->id])->toMatchArray(['current_category_id' => $old->id, 'rule_id' => $inactive->id, 'rule_name' => 'Cinema']);
    expect($items[$byInactive->id])->not->toHaveKey('new_category_id');
    expect($items[$deleted->id])->not->toHaveKey('rule_id');
    expect($items[$deleted->id])->not->toHaveKey('rule_name');
});

it('não é stale quando outra regra ativa dá a mesma categoria', function () {
    actingAsUser();
    $category = Category::factory()->create();
    $inactive = reviewRule('uber', $category, ['is_active' => false]);
    reviewRule('uber', $category, ['name' => 'Outra']);

    Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => $category->id, 'categorized_by' => "rule:{$inactive->id}"]);

    $groups = reviewGroups();

    expect(collect($groups)->sum('count'))->toBe(0);
});

it('regra desativada não preenche nem conflita', function () {
    actingAsUser();
    reviewRule('uber', Category::factory()->create(), ['is_active' => false]);
    Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);
    Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => Category::factory()->create()->id, 'categorized_by' => 'history']);

    expect(collect(reviewGroups())->sum('count'))->toBe(0);
});

it('primeira regra que define categoria vence, pela ordem das regras', function () {
    actingAsUser();
    $first = Category::factory()->create();
    $second = Category::factory()->create();
    // Casa antes, mas sem categoria: não decide nada.
    $payeeOnly = reviewRule('uber', $first, ['name' => 'Só favorecido', 'priority' => 0, 'actions' => [['type' => 'set_payee', 'value' => 'Uber BV']]]);
    $high = reviewRule('uber', $first, ['name' => 'Alta', 'priority' => 1]);
    $low = reviewRule('uber', $second, ['name' => 'Baixa', 'priority' => 2]);
    $transaction = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);

    expect(reviewGroups()['fill']['items'][0])->toMatchArray([
        'new_category_id' => $first->id, 'rule_id' => $high->id, 'rule_name' => 'Alta',
    ]);

    $this->putJson('/api/v1/rules/order', ['ids' => [$payeeOnly->id, $low->id, $high->id]])->assertNoContent();

    expect(reviewGroups()['fill']['items'][0])->toMatchArray([
        'new_category_id' => $second->id, 'rule_id' => $low->id,
    ]);

    $this->postJson('/api/v1/rules/review/apply', ['transaction_ids' => [$transaction->id]])->assertOk();

    $transaction->refresh();
    expect($transaction->category_id)->toBe($second->id);
    expect($transaction->categorized_by)->toBe("rule:{$low->id}");
});

it('deixa de fora perna de transferência, prevista de recorrência e pagamento de fatura', function () {
    actingAsUser();
    reviewRule('uber', Category::factory()->create());
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
    ]);

    expect(collect(reviewGroups())->sum('count'))->toBe(0);
});

it('stale nunca tira categoria de transferência', function () {
    actingAsUser();
    $parent = Category::factory()->transfer()->create();
    $child = Category::factory()->create(['parent_id' => $parent->id]);
    Transaction::factory()->create(['description' => 'Aplicação CDB', 'category_id' => $child->id, 'categorized_by' => 'rule:999999']);

    expect(reviewGroups()['stale']['count'])->toBe(0);
});

it('conflict nunca troca categoria de transferência por uma comum, só por outra de transferência', function () {
    actingAsUser();
    $invoice = Category::factory()->transfer()->create(['name' => 'Pagamento de fatura']);
    $investments = Category::factory()->transfer()->create(['name' => 'Investimentos']);
    $other = Category::factory()->create(['name' => 'Outros']);
    reviewRule('pagamento', $other);
    reviewRule('aplicação', $investments);

    $payment = Transaction::factory()->create([
        'description' => 'PAGAMENTO FATURA', 'amount' => 150000, 'category_id' => $invoice->id, 'categorized_by' => 'pluggy',
    ]);
    $toTransfer = Transaction::factory()->create([
        'description' => 'Aplicação CDB', 'category_id' => $invoice->id, 'categorized_by' => 'history',
    ]);

    $conflict = reviewGroups()['conflict'];

    expect($conflict['count'])->toBe(1);
    expect($conflict['items'][0]['transaction_id'])->toBe($toTransfer->id);

    $this->postJson('/api/v1/rules/review/apply', ['transaction_ids' => [$payment->id]])
        ->assertOk()
        ->assertJsonPath('data.applied', 0);

    expect($payment->refresh()->category_id)->toBe($invoice->id);
});

it('regra de outro usuário nunca categoriza lançamentos do usuário autenticado', function () {
    $other = User::factory()->create();
    $otherCategory = Category::factory()->create(['user_id' => $other->id]);
    Rule::factory()->create([
        'user_id' => $other->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $otherCategory->id]],
    ]);

    actingAsUser();
    $mine = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);

    expect(collect(reviewGroups())->sum('count'))->toBe(0);

    $this->postJson('/api/v1/rules/review/apply', ['transaction_ids' => [$mine->id]])
        ->assertOk()
        ->assertJsonPath('data.applied', 0);

    expect($mine->refresh()->category_id)->toBeNull();
});

it('à mão sem categoria (categoria excluída) entra em fill e recebe a categoria da regra', function () {
    actingAsUser();
    $transport = Category::factory()->create();
    $rule = reviewRule('uber', $transport);
    $orphan = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null, 'categorized_by' => 'manual']);

    $groups = reviewGroups();
    expect(collect($groups['fill']['items'])->pluck('transaction_id')->all())->toBe([$orphan->id]);
    expect($groups['manual']['count'])->toBe(0);

    $this->postJson('/api/v1/rules/review/apply', ['transaction_ids' => [$orphan->id]])
        ->assertOk()
        ->assertJsonPath('data.applied', 1);

    $orphan->refresh();
    expect($orphan->category_id)->toBe($transport->id);
    expect($orphan->categorized_by)->toBe("rule:{$rule->id}");
});

it('itens limitados a 500 por grupo, com a contagem completa e truncated', function () {
    $user = actingAsUser();
    reviewRule('uber', Category::factory()->create());
    $account = Account::factory()->create();
    insertBulkTransactions($user, $account, 501, ['description' => 'Uber eats']);

    $fill = reviewGroups()['fill'];

    expect($fill['count'])->toBe(501);
    expect($fill['truncated'])->toBeTrue();
    expect($fill['items'])->toHaveCount(500);
});

it('a revisão não grava nada', function () {
    actingAsUser();
    reviewRule('uber', Category::factory()->create());
    $transaction = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);

    reviewGroups();

    expect($transaction->refresh()->category_id)->toBeNull();
});

it('aplica fill, conflict e stale escolhidos; à mão e fora do escopo ficam como estão', function () {
    actingAsUser();
    $transport = Category::factory()->create();
    $food = Category::factory()->create();
    $rule = reviewRule('uber', $transport);

    $fill = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);
    $conflict = Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => $food->id, 'categorized_by' => 'pluggy']);
    $stale = Transaction::factory()->create(['description' => 'Academia', 'category_id' => $food->id, 'categorized_by' => 'rule:999999']);
    $manual = Transaction::factory()->create(['description' => 'Uber *mão', 'category_id' => $food->id, 'categorized_by' => 'manual']);
    $transfer = Transaction::factory()->create(['description' => 'Uber transf', 'transfer_id' => (string) Str::uuid()]);
    $nothing = Transaction::factory()->create(['description' => 'Padaria', 'category_id' => null]);
    $staleKey = $stale->description_key;

    $response = $this->postJson('/api/v1/rules/review/apply', [
        'transaction_ids' => [$fill->id, $conflict->id, $stale->id, $manual->id, $transfer->id, $nothing->id],
    ])->assertOk();

    expect($response->json('data'))->toBe(['applied' => 3, 'skipped' => 3]);

    $fill->refresh();
    expect($fill->category_id)->toBe($transport->id);
    expect($fill->categorized_by)->toBe("rule:{$rule->id}");

    $conflict->refresh();
    expect($conflict->category_id)->toBe($transport->id);
    expect($conflict->categorized_by)->toBe("rule:{$rule->id}");

    $stale->refresh();
    expect($stale->category_id)->toBeNull();
    expect($stale->categorized_by)->toBeNull();
    expect($stale->description_key)->toBe($staleKey);

    $manual->refresh();
    expect($manual->category_id)->toBe($food->id);
    expect($manual->categorized_by)->toBe('manual');

    expect($transfer->refresh()->category_id)->toBeNull();
    expect($nothing->refresh()->category_id)->toBeNull();
});

it('recalcula na hora de aplicar: quem mudou depois da prévia é pulado', function () {
    actingAsUser();
    $transport = Category::factory()->create();
    $food = Category::factory()->create();
    $rule = reviewRule('uber', $transport);

    $becameManual = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);
    $alreadyFixed = Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => $food->id, 'categorized_by' => 'history']);

    $groups = reviewGroups();
    expect($groups['fill']['count'])->toBe(1);
    expect($groups['conflict']['count'])->toBe(1);

    // Entre a prévia e a confirmação: um categorizado à mão, outro já
    // ganhou a categoria das regras por outro caminho.
    $becameManual->update(['category_id' => $food->id, 'categorized_by' => 'manual']);
    $alreadyFixed->update(['category_id' => $transport->id, 'categorized_by' => "rule:{$rule->id}"]);

    $this->postJson('/api/v1/rules/review/apply', ['transaction_ids' => [$becameManual->id, $alreadyFixed->id]])
        ->assertOk()
        ->assertJsonPath('data.applied', 0)
        ->assertJsonPath('data.skipped', 2);

    $becameManual->refresh();
    expect($becameManual->category_id)->toBe($food->id);
    expect($becameManual->categorized_by)->toBe('manual');
});

it('aplicar usa as regras de agora: regra desativada depois da prévia não preenche', function () {
    actingAsUser();
    $rule = reviewRule('uber', Category::factory()->create());
    $transaction = Transaction::factory()->create(['description' => 'Uber eats', 'category_id' => null]);

    expect(reviewGroups()['fill']['count'])->toBe(1);

    $rule->update(['is_active' => false]);

    $this->postJson('/api/v1/rules/review/apply', ['transaction_ids' => [$transaction->id]])
        ->assertOk()
        ->assertJsonPath('data.skipped', 1);

    expect($transaction->refresh()->category_id)->toBeNull();
});

it('422 sem ids ou com mais de 5000', function () {
    actingAsUser();

    $this->postJson('/api/v1/rules/review/apply', ['transaction_ids' => []])
        ->assertStatus(422)->assertJsonValidationErrors('transaction_ids');
    $this->postJson('/api/v1/rules/review/apply', ['transaction_ids' => range(1, 5001)])
        ->assertStatus(422)->assertJsonValidationErrors('transaction_ids');
});

it('só vê e só muda lançamentos do usuário autenticado', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $otherCategory = Category::factory()->create(['user_id' => $other->id]);
    Rule::factory()->create([
        'user_id' => $other->id,
        'conditions' => [['field' => 'description', 'op' => 'contains', 'value' => 'uber']],
        'actions' => [['type' => 'set_category', 'category_id' => $otherCategory->id]],
    ]);
    $otherTransaction = Transaction::factory()->create([
        'user_id' => $other->id, 'account_id' => $otherAccount->id, 'description' => 'Uber eats', 'category_id' => null,
    ]);

    actingAsUser();
    reviewRule('uber', Category::factory()->create());

    expect(collect(reviewGroups())->sum('count'))->toBe(0);

    $this->postJson('/api/v1/rules/review/apply', ['transaction_ids' => [$otherTransaction->id]])
        ->assertOk()
        ->assertJsonPath('data.applied', 0)
        ->assertJsonPath('data.skipped', 1);

    expect(Transaction::query()->withoutGlobalScopes()->find($otherTransaction->id)->category_id)->toBeNull();
});
