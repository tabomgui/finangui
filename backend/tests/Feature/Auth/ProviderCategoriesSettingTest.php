<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Reports\Queries\MonthSummary;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Lançamento inventado do usuário com a categoria marcada pela origem
 * informada (manual, history, pluggy, rule:{id}).
 */
function categorizedTransaction(User $user, string $categorizedBy, string $description = 'Compra Exemplo'): Transaction
{
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);

    return Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => $description,
        'category_id' => $category->id,
        'categorized_by' => $categorizedBy,
    ]);
}

it('vem desligado por padrão para todo usuário e aparece em /me', function () {
    $user = actingAsUser();

    expect($user->use_provider_categories)->toBeFalse()
        ->and($user->refresh()->use_provider_categories)->toBeFalse();

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.use_provider_categories', false);
});

it('liga e desliga pelo PATCH /me', function () {
    $user = actingAsUser();

    $this->patchJson('/api/v1/me', ['use_provider_categories' => true])
        ->assertOk()
        ->assertJsonPath('data.use_provider_categories', true)
        ->assertJsonPath('meta.cleared_provider_categories', 0);

    expect($user->refresh()->use_provider_categories)->toBeTrue();

    $this->patchJson('/api/v1/me', ['use_provider_categories' => false])
        ->assertOk()
        ->assertJsonPath('data.use_provider_categories', false);

    expect($user->refresh()->use_provider_categories)->toBeFalse();
});

it('desligar sem clear_provider_categories mantém as categorias vindas do banco', function () {
    $user = actingAsUser(['use_provider_categories' => true]);
    $fromProvider = categorizedTransaction($user, 'pluggy');

    $this->patchJson('/api/v1/me', ['use_provider_categories' => false])
        ->assertOk()
        ->assertJsonPath('meta.cleared_provider_categories', 0);

    expect($fromProvider->refresh()->categorized_by)->toBe('pluggy')
        ->and($fromProvider->category_id)->not->toBeNull();
});

it('desligar com clear_provider_categories tira a categoria só dos lançamentos categorizados pelo banco', function () {
    $user = actingAsUser(['use_provider_categories' => true]);

    $fromProvider = categorizedTransaction($user, 'pluggy', 'Academia Exemplo');
    $alsoFromProvider = categorizedTransaction($user, 'pluggy');
    $manual = categorizedTransaction($user, 'manual');
    $history = categorizedTransaction($user, 'history');
    $rule = categorizedTransaction($user, 'rule:1');
    $descriptionKey = $fromProvider->description_key;

    $this->patchJson('/api/v1/me', ['use_provider_categories' => false, 'clear_provider_categories' => true])
        ->assertOk()
        ->assertJsonPath('data.use_provider_categories', false)
        ->assertJsonPath('meta.cleared_provider_categories', 2);

    $fromProvider->refresh();
    expect($fromProvider->category_id)->toBeNull()
        ->and($fromProvider->categorized_by)->toBeNull()
        ->and($fromProvider->description_key)->toBe($descriptionKey)
        ->and($alsoFromProvider->refresh()->category_id)->toBeNull();

    foreach ([[$manual, 'manual'], [$history, 'history'], [$rule, 'rule:1']] as [$transaction, $categorizedBy]) {
        $categoryId = $transaction->category_id;
        $transaction->refresh();

        expect($transaction->categorized_by)->toBe($categorizedBy)
            ->and($transaction->category_id)->toBe($categoryId);
    }
});

it('a limpeza e a contagem nunca incluem categoria de transferência, nem pelo pai, e o total de despesas do mês não muda', function () {
    $user = actingAsUser(['use_provider_categories' => true]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    $groceries = Category::factory()->create(['user_id' => $user->id, 'name' => 'Mercado']);
    $cardPayment = Category::factory()->transfer()->create(['user_id' => $user->id, 'name' => 'Pagamento de fatura']);
    $investments = Category::factory()->transfer()->create(['user_id' => $user->id, 'name' => 'Investimentos']);
    $treasury = Category::factory()->create(['user_id' => $user->id, 'name' => 'Tesouro', 'parent_id' => $investments->id]);

    $make = fn (Category $category, int $amount) => Transaction::factory()->create([
        'user_id' => $user->id, 'account_id' => $account->id, 'date' => '2026-09-10',
        'amount' => $amount, 'category_id' => $category->id, 'categorized_by' => 'pluggy',
    ]);
    $plain = $make($groceries, 10000);
    $payment = $make($cardPayment, 50000);
    $invested = $make($treasury, 30000);

    $expense = fn (): int => app(MonthSummary::class)
        ->for(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'))['expense'];
    $expenseBefore = $expense();

    $this->getJson('/api/v1/categorization/provider-categorized-count')
        ->assertOk()
        ->assertJsonPath('data.count', 1);

    $this->patchJson('/api/v1/me', ['use_provider_categories' => false, 'clear_provider_categories' => true])
        ->assertOk()
        ->assertJsonPath('meta.cleared_provider_categories', 1);

    expect($plain->refresh()->category_id)->toBeNull()
        ->and($payment->refresh()->category_id)->toBe($cardPayment->id)
        ->and($payment->categorized_by)->toBe('pluggy')
        ->and($invested->refresh()->category_id)->toBe($treasury->id)
        ->and($invested->categorized_by)->toBe('pluggy')
        ->and($expenseBefore)->toBe(10000)
        ->and($expense())->toBe($expenseBefore);
});

it('a limpeza nunca toca lançamento de outro usuário', function () {
    $other = User::factory()->create(['use_provider_categories' => true]);
    $othersTransaction = categorizedTransaction($other, 'pluggy');

    $user = actingAsUser(['use_provider_categories' => true]);
    categorizedTransaction($user, 'pluggy');

    $this->patchJson('/api/v1/me', ['use_provider_categories' => false, 'clear_provider_categories' => true])
        ->assertOk()
        ->assertJsonPath('meta.cleared_provider_categories', 1);

    $othersTransaction = Transaction::query()->withoutGlobalScopes()->find($othersTransaction->id);
    expect($othersTransaction->categorized_by)->toBe('pluggy')
        ->and($othersTransaction->category_id)->not->toBeNull()
        ->and($other->refresh()->use_provider_categories)->toBeTrue();
});

it('com a configuração já desligada, clear_provider_categories ainda limpa', function () {
    $user = actingAsUser();
    categorizedTransaction($user, 'pluggy');

    $this->patchJson('/api/v1/me', ['use_provider_categories' => false, 'clear_provider_categories' => true])
        ->assertOk()
        ->assertJsonPath('meta.cleared_provider_categories', 1);
});

it('valida use_provider_categories e clear_provider_categories', function () {
    $user = actingAsUser();
    categorizedTransaction($user, 'pluggy');

    $this->patchJson('/api/v1/me', ['use_provider_categories' => 'talvez'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('use_provider_categories');

    $this->patchJson('/api/v1/me', ['use_provider_categories' => null])
        ->assertStatus(422)
        ->assertJsonValidationErrors('use_provider_categories');

    $this->patchJson('/api/v1/me', ['use_provider_categories' => false, 'clear_provider_categories' => 'sim'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('clear_provider_categories');

    // Limpar só faz sentido desligando.
    $this->patchJson('/api/v1/me', ['clear_provider_categories' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('clear_provider_categories');

    $this->patchJson('/api/v1/me', ['use_provider_categories' => true, 'clear_provider_categories' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('clear_provider_categories');

    // false explícito não pede limpeza nenhuma, então é aceito junto de ligar.
    $this->patchJson('/api/v1/me', ['use_provider_categories' => true, 'clear_provider_categories' => false])
        ->assertOk()
        ->assertJsonPath('meta.cleared_provider_categories', 0);

    expect(Transaction::query()->where('categorized_by', 'pluggy')->count())->toBe(1);
});

it('a contagem de lançamentos categorizados pelo banco é só do usuário e ignora marcador sem categoria', function () {
    $other = User::factory()->create();
    categorizedTransaction($other, 'pluggy');

    $user = actingAsUser();
    categorizedTransaction($user, 'pluggy');
    categorizedTransaction($user, 'pluggy');
    categorizedTransaction($user, 'manual');
    categorizedTransaction($user, 'history');
    // Categoria excluída depois: o marcador fica, mas não há categoria do banco para remover.
    $orphan = categorizedTransaction($user, 'pluggy');
    $orphan->forceFill(['category_id' => null])->save();

    $this->getJson('/api/v1/categorization/provider-categorized-count')
        ->assertOk()
        ->assertExactJson(['data' => ['count' => 2]]);
});

it('exige autenticação para ler a contagem e para mudar a configuração', function () {
    $this->getJson('/api/v1/categorization/provider-categorized-count')->assertUnauthorized();
    $this->patchJson('/api/v1/me', ['use_provider_categories' => false, 'clear_provider_categories' => true])->assertUnauthorized();
});
