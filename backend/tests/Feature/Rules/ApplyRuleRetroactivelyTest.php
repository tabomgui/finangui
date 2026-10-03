<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Data\RuleDefinition;
use App\Domain\Rules\Jobs\ApplyRuleRetroactively;
use App\Domain\Rules\Models\Rule;
use App\Domain\Rules\Queries\PreviewRule;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

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

it('422 quando overwrite não é booleano', function () {
    actingAsUser();
    $rule = uberRule();

    $this->postJson("/api/v1/rules/{$rule->id}/apply", ['overwrite' => 'abc'])
        ->assertStatus(422)->assertJsonValidationErrors('overwrite');
});

it('aceita overwrite como string "true" normalizada', function () {
    Bus::fake();
    actingAsUser();
    $rule = uberRule();

    $this->postJson("/api/v1/rules/{$rule->id}/apply", ['overwrite' => 'true'])->assertStatus(202);

    Bus::assertDispatched(ApplyRuleRetroactively::class, fn (ApplyRuleRetroactively $job) => $job->overwrite === true);
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
    $transaction = Transaction::factory()->create(['description' => 'Uber *trip', 'category_id' => null]);

    ApplyRuleRetroactively::dispatch($ruleId, $user->id);

    expect(Rule::query()->withoutGlobalScopes()->find($ruleId))->toBeNull();
    expect($transaction->refresh()->category_id)->toBeNull();
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

it('é único por regra: despachar duas vezes só enfileira uma', function () {
    Queue::fake();
    $user = actingAsUser();
    $rule = uberRule();

    ApplyRuleRetroactively::dispatch($rule->id, $user->id);
    ApplyRuleRetroactively::dispatch($rule->id, $user->id);

    Queue::assertPushed(ApplyRuleRetroactively::class, 1);
});

it('aplicar duas vezes em seguida: a segunda responde 409 em vez de enfileirar em silêncio', function () {
    Queue::fake();
    actingAsUser();
    $rule = uberRule();

    $this->postJson("/api/v1/rules/{$rule->id}/apply")->assertStatus(202);

    $this->postJson("/api/v1/rules/{$rule->id}/apply")
        ->assertStatus(409)
        ->assertJsonPath('code', 'rule_apply_in_progress');

    Queue::assertPushed(ApplyRuleRetroactively::class, 1);
});

it('duas regras diferentes não são bloqueadas uma pela outra', function () {
    Queue::fake();
    $user = actingAsUser();
    $a = uberRule();
    $b = uberRule();

    ApplyRuleRetroactively::dispatch($a->id, $user->id);
    ApplyRuleRetroactively::dispatch($b->id, $user->id);

    Queue::assertPushed(ApplyRuleRetroactively::class, 2);
});

it('falha registra um aviso no log com o id da regra', function () {
    Log::spy();
    $rule = uberRule();
    $job = new ApplyRuleRetroactively($rule->id, 1);

    $job->failed(new RuntimeException('falha simulada'));

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context) => $context['rule_id'] === $rule->id,
    );
});

it('consistência: a prévia conta tantas mudanças quanto o job de fato aplica, com e sem overwrite', function () {
    $user = actingAsUser();
    $category = Category::factory()->create();
    $archivedTarget = Category::factory()->create(['is_archived' => true]);
    $tag = Tag::factory()->create();

    // Mistura: manual (protegida), por histórico (substituível com overwrite),
    // descrição travada (set_description não se aplica), tag já presente,
    // já ignorada e uma que casaria mas a categoria alvo está arquivada.
    Transaction::factory()->create(['description' => 'Uber *trip 1', 'category_id' => null]);
    Transaction::factory()->create([
        'description' => 'Uber *trip 2', 'category_id' => Category::factory()->create()->id, 'categorized_by' => 'manual',
    ]);
    Transaction::factory()->create([
        'description' => 'Uber *trip 3', 'category_id' => Category::factory()->create()->id, 'categorized_by' => 'history',
    ]);
    $locked = Transaction::factory()->create([
        'description' => 'Uber *trip 4', 'category_id' => null, 'description_locked' => true,
    ]);
    $taggedAlready = Transaction::factory()->create(['description' => 'Uber *trip 5', 'category_id' => null]);
    $taggedAlready->tags()->attach($tag->id);
    Transaction::factory()->create(['description' => 'Uber *trip 6', 'category_id' => null, 'is_ignored' => true]);

    $rule = uberRule([
        'actions' => [
            ['type' => 'set_category', 'category_id' => $category->id],
            ['type' => 'set_description', 'value' => 'Uber'],
            ['type' => 'add_tag', 'tag_id' => $tag->id],
        ],
    ]);
    $ruleArchivedCategory = uberRule(['actions' => [['type' => 'set_category', 'category_id' => $archivedTarget->id]]]);

    foreach ([$rule, $ruleArchivedCategory] as $r) {
        foreach ([false, true] as $overwrite) {
            $definition = RuleDefinition::fromRule($r);
            $preview = app(PreviewRule::class)->handle($definition, $overwrite);

            ApplyRuleRetroactively::dispatch($r->id, $user->id, $overwrite);

            expect($preview->changed)->toBe($r->refresh()->last_applied_changes);
        }
    }

    // A ação set_description nunca se aplica à travada; a tag não conta de novo na já marcada.
    expect($locked->refresh()->description)->toBe('Uber *trip 4');
    expect($taggedAlready->refresh()->tags->pluck('id')->all())->toBe([$tag->id]);
});
