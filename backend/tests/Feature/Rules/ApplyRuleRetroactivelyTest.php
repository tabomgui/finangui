<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Jobs\ApplyRuleRetroactively;
use App\Domain\Rules\Models\Rule;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

function uberRule(array $overrides = []): Rule
{
    return Rule::factory()->create(array_merge([
        'conditions' => [
            ['field' => 'description', 'op' => 'contains', 'value' => 'uber'],
        ],
    ], $overrides));
}

it('enfileira a aplicação retroativa e responde 202', function () {
    Bus::fake();
    actingAsUser();
    $rule = uberRule();

    $this->postJson("/api/v1/rules/{$rule->id}/apply")
        ->assertStatus(202)
        ->assertJsonPath('data.queued', true);

    Bus::assertDispatched(ApplyRuleRetroactively::class, fn (ApplyRuleRetroactively $job) => $job->ruleId === $rule->id && $job->overwrite === false);
});

it('aceita overwrite no corpo', function () {
    Bus::fake();
    $user = actingAsUser();
    $rule = uberRule();

    $this->postJson("/api/v1/rules/{$rule->id}/apply", ['overwrite' => true])->assertStatus(202);

    Bus::assertDispatched(ApplyRuleRetroactively::class, fn (ApplyRuleRetroactively $job) => $job->userId === $user->id && $job->overwrite === true);
});

it('regra inativa também pode ser aplicada', function () {
    Bus::fake();
    actingAsUser();
    $rule = uberRule(['is_active' => false]);

    $this->postJson("/api/v1/rules/{$rule->id}/apply")->assertStatus(202);

    Bus::assertDispatched(ApplyRuleRetroactively::class, fn (ApplyRuleRetroactively $job) => $job->ruleId === $rule->id);
});

it('404 ao aplicar regra de outro usuário', function () {
    actingAsUser();
    $other = User::factory()->create();
    $rule = uberRule(['user_id' => $other->id]);

    $this->postJson("/api/v1/rules/{$rule->id}/apply")->assertNotFound();
});

it('aplica categoria, descrição, tag e ignorar nas transações que casam', function () {
    $user = actingAsUser();
    $tag = Tag::factory()->create();
    $category = Category::factory()->create();

    $rule = uberRule([
        'actions' => [
            ['type' => 'set_category', 'category_id' => $category->id],
            ['type' => 'set_description', 'value' => 'Uber'],
            ['type' => 'add_tag', 'tag_id' => $tag->id],
            ['type' => 'ignore'],
        ],
    ]);
    $transaction = Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => null]);
    $untouched = Transaction::factory()->create(['description' => 'Padaria']);

    ApplyRuleRetroactively::dispatch($rule->id, $user->id);

    $transaction->refresh();
    expect($transaction->category_id)->toBe($category->id);
    expect($transaction->categorized_by)->toBe("rule:{$rule->id}");
    expect($transaction->description)->toBe('Uber');
    expect($transaction->tags->pluck('id')->all())->toBe([$tag->id]);
    expect($transaction->is_ignored)->toBeTrue();

    expect($untouched->refresh()->category_id)->toBeNull();
});

it('overwrite substitui categoria de regra/histórico, mas não a definida à mão', function () {
    $user = actingAsUser();
    $newCategory = Category::factory()->create();

    $byHistory = Transaction::factory()->create([
        'description' => 'Uber *trip 1', 'category_id' => Category::factory()->create()->id, 'categorized_by' => 'history',
    ]);
    $manual = Transaction::factory()->create([
        'description' => 'Uber *trip 2', 'category_id' => Category::factory()->create()->id, 'categorized_by' => 'manual',
    ]);
    $manualCategoryId = $manual->category_id;

    $rule = uberRule([
        'actions' => [['type' => 'set_category', 'category_id' => $newCategory->id]],
    ]);

    ApplyRuleRetroactively::dispatch($rule->id, $user->id, overwrite: true);

    expect($byHistory->refresh()->category_id)->toBe($newCategory->id);
    expect($manual->refresh()->category_id)->toBe($manualCategoryId);
});

it('grava last_applied_at e last_applied_changes na regra', function () {
    $user = actingAsUser();
    Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => null]);
    $rule = uberRule();

    expect($rule->last_applied_at)->toBeNull();

    ApplyRuleRetroactively::dispatch($rule->id, $user->id);

    $rule->refresh();
    expect($rule->last_applied_at)->not->toBeNull();
    expect($rule->last_applied_changes)->toBe(1);
});

it('rodar duas vezes: a segunda não encontra mais nada a mudar', function () {
    $user = actingAsUser();
    Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => null]);
    $rule = uberRule();

    ApplyRuleRetroactively::dispatch($rule->id, $user->id);
    expect($rule->refresh()->last_applied_changes)->toBe(1);

    ApplyRuleRetroactively::dispatch($rule->id, $user->id);
    expect($rule->refresh()->last_applied_changes)->toBe(0);
});

it('regra excluída antes do job rodar: não faz nada', function () {
    $user = actingAsUser();
    $rule = uberRule();
    $ruleId = $rule->id;
    $rule->delete();

    ApplyRuleRetroactively::dispatch($ruleId, $user->id);

    expect(Rule::query()->withoutGlobalScopes()->find($ruleId))->toBeNull();
});

it('não toca em pernas de transferência', function () {
    $user = actingAsUser();
    $account = Account::factory()->create();
    $transferId = (string) Str::uuid();
    $leg = Transaction::factory()->create([
        'account_id' => $account->id, 'description' => 'Uber *trip', 'transfer_id' => $transferId, 'category_id' => null,
    ]);
    $rule = uberRule();

    ApplyRuleRetroactively::dispatch($rule->id, $user->id);

    expect($leg->refresh()->category_id)->toBeNull();
});

it('restaura o guard sem usuário autenticado depois de rodar', function () {
    $user = actingAsUser();
    $rule = uberRule();
    Auth::forgetUser();

    ApplyRuleRetroactively::dispatch($rule->id, $user->id);

    expect(Auth::hasUser())->toBeFalse();
});
