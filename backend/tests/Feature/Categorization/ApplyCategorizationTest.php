<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Categorization\Actions\ApplyCategorization;
use App\Domain\Rules\Models\Rule;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;

function applyCategorizationPayload(array $overrides = []): array
{
    return array_merge([
        'transaction_ids' => [],
        'category_id' => 0,
    ], $overrides);
}

describe('autenticado', function () {
    beforeEach(function () {
        $this->user = actingAsUser();
    });

    it('categoriza os pendentes, grava categorized_by manual e devolve a contagem', function () {
        $category = Category::factory()->create();
        $a = Transaction::factory()->create(['description' => 'Padaria Teste']);
        $b = Transaction::factory()->create(['description' => 'Padaria Teste']);

        $response = $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$a->id, $b->id],
            'category_id' => $category->id,
        ])->assertOk();

        $response->assertJson(['data' => ['categorized' => 2, 'skipped' => 0]])
            ->assertJsonMissingPath('data.rule')
            ->assertJsonMissingPath('data.rule_applied');

        expect($a->refresh()->category_id)->toBe($category->id)
            ->and($a->refresh()->categorized_by)->toBe('manual')
            ->and($b->refresh()->category_id)->toBe($category->id);
    });

    it('conta skipped os ids que não estão mais pendentes, sem erro', function () {
        $category = Category::factory()->create();
        $pending = Transaction::factory()->create();
        $otherCategory = Category::factory()->create();
        $alreadyCategorized = Transaction::factory()->create(['category_id' => $otherCategory->id]);

        $response = $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$pending->id, $alreadyCategorized->id],
            'category_id' => $category->id,
        ])->assertOk();

        $response->assertJson(['data' => ['categorized' => 1, 'skipped' => 1]]);

        expect($pending->refresh()->category_id)->toBe($category->id)
            ->and($alreadyCategorized->refresh()->category_id)->toBe($otherCategory->id);
    });

    it('cria a regra com as condições recebidas, ação set_category e prioridade no fim', function () {
        $category = Category::factory()->create();
        Rule::factory()->create(['priority' => 0]);
        $transaction = Transaction::factory()->create(['description' => 'Pix enviado - Fulano', 'original_description' => 'Pix enviado - Fulano']);

        $response = $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$transaction->id],
            'category_id' => $category->id,
            'rule' => [
                'name' => 'Fulano -> Categoria',
                'match' => 'any',
                'conditions' => [
                    ['field' => 'original_description', 'op' => 'contains', 'value' => 'Fulano'],
                ],
            ],
        ])->assertOk();

        $ruleId = $response->json('data.rule.id');
        expect($response->json('data.rule.name'))->toBe('Fulano -> Categoria');

        $rule = Rule::find($ruleId);
        expect($rule->match)->toBe('any')
            ->and($rule->conditions)->toHaveCount(1)
            ->and($rule->conditions[0])->toMatchArray(['field' => 'original_description', 'op' => 'contains', 'value' => 'Fulano'])
            ->and($rule->actions)->toHaveCount(1)
            ->and($rule->actions[0])->toMatchArray(['type' => 'set_category', 'category_id' => $category->id])
            ->and($rule->priority)->toBe(1)
            ->and($rule->is_active)->toBeTrue();
    });

    it('apply_to_pending categoriza outros pendentes que a regra casa, sem sobrescrever quem já tem categoria, e devolve rule_applied', function () {
        $category = Category::factory()->create();
        $marked = Transaction::factory()->create(['description' => 'Pix enviado - Fulano', 'original_description' => 'Pix enviado - Fulano']);
        $otherPending = Transaction::factory()->create(['description' => 'Pix enviado - Fulano 2', 'original_description' => 'Pix enviado - Fulano 2']);
        $otherCategory = Category::factory()->create();
        $alreadyCategorized = Transaction::factory()->create([
            'description' => 'Pix enviado - Fulano 3', 'original_description' => 'Pix enviado - Fulano 3', 'category_id' => $otherCategory->id,
        ]);
        $unrelated = Transaction::factory()->create(['description' => 'Mercado', 'original_description' => 'Mercado']);

        $response = $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$marked->id],
            'category_id' => $category->id,
            'rule' => [
                'name' => 'Fulano -> Categoria',
                'match' => 'any',
                'conditions' => [
                    ['field' => 'original_description', 'op' => 'contains', 'value' => 'Fulano'],
                ],
                'apply_to_pending' => true,
            ],
        ])->assertOk();

        $response->assertJson(['data' => ['categorized' => 1, 'rule_applied' => 1]]);

        expect($marked->refresh()->category_id)->toBe($category->id)
            ->and($otherPending->refresh()->category_id)->toBe($category->id)
            ->and($otherPending->refresh()->categorized_by)->toBe('rule:'.$response->json('data.rule.id'))
            ->and($alreadyCategorized->refresh()->category_id)->toBe($otherCategory->id)
            ->and($unrelated->refresh()->category_id)->toBeNull();
    });

    it('sem rule, a resposta omite rule e rule_applied', function () {
        $category = Category::factory()->create();
        $transaction = Transaction::factory()->create();

        $response = $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$transaction->id],
            'category_id' => $category->id,
        ])->assertOk();

        expect($response->json('data'))->not->toHaveKeys(['rule', 'rule_applied']);
    });

    it('é atômico: um erro na criação da regra não deixa nada gravado', function () {
        $category = Category::factory()->create();
        $transaction = Transaction::factory()->create();

        // Chama a action direto (sem passar pela validação de 80 caracteres
        // do ApplyCategorizationRequest), para a coluna rules.name
        // (string(80) no banco) rejeitar a gravação e provar que a
        // categorização já feita acima também é desfeita.
        expect(fn () => app(ApplyCategorization::class)->handle(
            transactionIds: [$transaction->id],
            categoryId: $category->id,
            ruleInput: [
                'name' => str_repeat('x', 81),
                'match' => 'any',
                'conditions' => [
                    ['field' => 'description', 'op' => 'contains', 'value' => 'x'],
                ],
                'apply_to_pending' => false,
            ],
        ))->toThrow(QueryException::class);

        expect($transaction->refresh()->category_id)->toBeNull()
            ->and(Rule::count())->toBe(0);
    });

    it('422 em transaction_ids quando algum id é de outro usuário', function () {
        $category = Category::factory()->create();
        $other = User::factory()->create();
        $otherTransaction = Transaction::factory()->create(['user_id' => $other->id, 'account_id' => Account::factory()->create(['user_id' => $other->id])->id]);

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$otherTransaction->id],
            'category_id' => $category->id,
        ])->assertStatus(422)->assertJsonValidationErrors('transaction_ids.0');
    });

    it('422 em transaction_ids quando a direção é misturada', function () {
        $category = Category::factory()->create();
        $out = Transaction::factory()->create(['direction' => 'out']);
        $in = Transaction::factory()->income()->create();

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$out->id, $in->id],
            'category_id' => $category->id,
        ])->assertStatus(422)->assertJsonValidationErrors('transaction_ids');
    });

    it('422 em category_id quando a categoria é de outro usuário', function () {
        $other = User::factory()->create();
        $otherCategory = Category::factory()->create(['user_id' => $other->id]);
        $transaction = Transaction::factory()->create();

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$transaction->id],
            'category_id' => $otherCategory->id,
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');
    });

    it('422 em category_id quando a categoria está arquivada', function () {
        $category = Category::factory()->create(['is_archived' => true]);
        $transaction = Transaction::factory()->create();

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$transaction->id],
            'category_id' => $category->id,
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');
    });

    it('422 em category_id quando o tipo da categoria não combina com a direção', function () {
        $incomeCategory = Category::factory()->income()->create();
        $outTransaction = Transaction::factory()->create(['direction' => 'out']);

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$outTransaction->id],
            'category_id' => $incomeCategory->id,
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');

        $expenseCategory = Category::factory()->create();
        $inTransaction = Transaction::factory()->income()->create();

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$inTransaction->id],
            'category_id' => $expenseCategory->id,
        ])->assertStatus(422)->assertJsonValidationErrors('category_id');
    });

    it('categoria is_transfer serve para as duas direções', function () {
        $transferCategory = Category::factory()->transfer()->create();
        $out = Transaction::factory()->create(['direction' => 'out']);
        $in = Transaction::factory()->income()->create();

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$out->id],
            'category_id' => $transferCategory->id,
        ])->assertOk();

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$in->id],
            'category_id' => $transferCategory->id,
        ])->assertOk();
    });

    it('422 em rule.conditions.* reaproveitando a validação de /rules', function () {
        $category = Category::factory()->create();
        $transaction = Transaction::factory()->create();

        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$transaction->id],
            'category_id' => $category->id,
            'rule' => [
                'name' => 'Nome',
                'match' => 'any',
                'conditions' => [
                    ['field' => 'amount', 'op' => 'contains', 'value' => 100],
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors('rule.conditions.0.op');
    });

    it('throttle próprio de categorization-apply', function () {
        $category = Category::factory()->create();

        for ($i = 0; $i < 30; $i++) {
            $transaction = Transaction::factory()->create();
            $this->postJson('/api/v1/categorization/apply', [
                'transaction_ids' => [$transaction->id],
                'category_id' => $category->id,
            ]);
        }

        $transaction = Transaction::factory()->create();
        $this->postJson('/api/v1/categorization/apply', [
            'transaction_ids' => [$transaction->id],
            'category_id' => $category->id,
        ])->assertStatus(429);
    });
});

it('POST /categorization/apply exige login', function () {
    $this->postJson('/api/v1/categorization/apply', applyCategorizationPayload())->assertUnauthorized();
});
