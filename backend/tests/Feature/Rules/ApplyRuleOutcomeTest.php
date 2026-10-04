<?php

use App\Domain\Categories\Models\Category;
use App\Domain\Rules\Actions\ApplyRuleOutcome;
use App\Domain\Rules\Data\RuleOutcome;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;
use Illuminate\Support\Str;

beforeEach(function () {
    actingAsUser();
    $this->action = new ApplyRuleOutcome;
});

it('aplica a categoria quando a transação não tem e grava categorized_by com o id da regra', function () {
    $category = Category::factory()->create();
    $transaction = Transaction::factory()->create(['category_id' => null])->load('tags');

    $outcome = new RuleOutcome;
    $outcome->categoryId = $category->id;
    $outcome->categoryRuleId = 7;

    expect($this->action->changes($transaction, $outcome))->toBe(['category_id' => $category->id]);
    expect($this->action->handle($transaction, $outcome))->toBeTrue();

    $transaction->refresh();
    expect($transaction->category_id)->toBe($category->id);
    expect($transaction->categorized_by)->toBe('rule:7');
});

it('categoria igual à atual não conta como mudança', function () {
    $category = Category::factory()->create();
    $transaction = Transaction::factory()->create(['category_id' => $category->id])->load('tags');

    $outcome = new RuleOutcome;
    $outcome->categoryId = $category->id;
    $outcome->categoryRuleId = 1;

    expect($this->action->changes($transaction, $outcome))->toBe([]);
    expect($this->action->handle($transaction, $outcome))->toBeFalse();
});

it('categoria excluída depois de a regra ser salva é ignorada em silêncio', function () {
    $transaction = Transaction::factory()->create(['category_id' => null])->load('tags');

    $outcome = new RuleOutcome;
    $outcome->categoryId = 999999;
    $outcome->categoryRuleId = 1;

    expect($this->action->changes($transaction, $outcome))->toBe([]);
    expect($this->action->handle($transaction, $outcome))->toBeFalse();
});

it('categoria arquivada depois de a regra ser salva é ignorada em silêncio', function () {
    $archived = Category::factory()->create(['is_archived' => true]);
    $transaction = Transaction::factory()->create(['category_id' => null])->load('tags');

    $outcome = new RuleOutcome;
    $outcome->categoryId = $archived->id;
    $outcome->categoryRuleId = 1;

    expect($this->action->changes($transaction, $outcome))->toBe([]);
    expect($this->action->handle($transaction, $outcome))->toBeFalse();
});

it('aplica descrição sem travar nem mexer na original', function () {
    $transaction = Transaction::factory()->create([
        'description' => 'PAG*IFOOD', 'original_description' => 'PAG*IFOOD', 'description_locked' => false,
    ])->load('tags');

    $outcome = new RuleOutcome;
    $outcome->description = 'iFood';

    expect($this->action->changes($transaction, $outcome))->toBe(['description' => 'iFood']);
    expect($this->action->handle($transaction, $outcome))->toBeTrue();

    $transaction->refresh();
    expect($transaction->description)->toBe('iFood');
    expect($transaction->original_description)->toBe('PAG*IFOOD');
    expect($transaction->description_locked)->toBeFalse();
});

it('aplica favorecido quando diferente do atual', function () {
    $transaction = Transaction::factory()->create(['payee' => null])->load('tags');

    $outcome = new RuleOutcome;
    $outcome->payee = 'Uber';

    expect($this->action->changes($transaction, $outcome))->toBe(['payee' => 'Uber']);
    expect($this->action->handle($transaction, $outcome))->toBeTrue();

    $transaction->refresh();
    expect($transaction->payee)->toBe('Uber');
});

it('acrescenta tags que ainda não estão na transação', function () {
    $tag = Tag::factory()->create();
    $transaction = Transaction::factory()->create()->load('tags');

    $outcome = new RuleOutcome;
    $outcome->tagIds = [$tag->id];

    expect($this->action->changes($transaction, $outcome))->toBe(['tag_ids' => [$tag->id]]);
    expect($this->action->handle($transaction, $outcome))->toBeTrue();

    $transaction->refresh();
    expect($transaction->tags->pluck('id')->all())->toBe([$tag->id]);
});

it('aplicar o mesmo outcome duas vezes só muda na primeira', function () {
    $tag = Tag::factory()->create();
    $transaction = Transaction::factory()->create()->load('tags');

    $outcome = new RuleOutcome;
    $outcome->tagIds = [$tag->id];

    expect($this->action->handle($transaction, $outcome))->toBeTrue();
    expect($this->action->handle($transaction, $outcome))->toBeFalse();
});

it('tag já presente não conta como mudança', function () {
    $tag = Tag::factory()->create();
    $transaction = Transaction::factory()->create();
    $transaction->tags()->sync([$tag->id]);
    $transaction->load('tags');

    $outcome = new RuleOutcome;
    $outcome->tagIds = [$tag->id];

    expect($this->action->changes($transaction, $outcome))->toBe([]);
    expect($this->action->handle($transaction, $outcome))->toBeFalse();
});

it('tag excluída depois de a regra ser salva é ignorada em silêncio', function () {
    $transaction = Transaction::factory()->create()->load('tags');

    $outcome = new RuleOutcome;
    $outcome->tagIds = [999999];

    expect($this->action->changes($transaction, $outcome))->toBe([]);
    expect($this->action->handle($transaction, $outcome))->toBeFalse();
});

it('marca como ignorada quando o outcome ignora e ainda não está', function () {
    $transaction = Transaction::factory()->create(['is_ignored' => false])->load('tags');

    $outcome = new RuleOutcome;
    $outcome->ignore = true;

    expect($this->action->changes($transaction, $outcome))->toBe(['is_ignored' => true]);
    expect($this->action->handle($transaction, $outcome))->toBeTrue();

    $transaction->refresh();
    expect($transaction->is_ignored)->toBeTrue();
});

it('já ignorada não conta como mudança', function () {
    $transaction = Transaction::factory()->create(['is_ignored' => true])->load('tags');

    $outcome = new RuleOutcome;
    $outcome->ignore = true;

    expect($this->action->changes($transaction, $outcome))->toBe([]);
    expect($this->action->handle($transaction, $outcome))->toBeFalse();
});

it('nunca mexe em perna de transferência', function () {
    $category = Category::factory()->create();
    $transaction = Transaction::factory()->create(['category_id' => null, 'transfer_id' => (string) Str::uuid()])->load('tags');

    $outcome = new RuleOutcome;
    $outcome->categoryId = $category->id;
    $outcome->description = 'x';
    $outcome->payee = 'y';
    $outcome->ignore = true;

    expect($this->action->changes($transaction, $outcome))->toBe([]);
    expect($this->action->handle($transaction, $outcome))->toBeFalse();

    $transaction->refresh();
    expect($transaction->category_id)->toBeNull();
    expect($transaction->is_ignored)->toBeFalse();
});
