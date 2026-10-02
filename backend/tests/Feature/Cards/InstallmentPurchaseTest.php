<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Cards\Support\StatementResolver;
use App\Domain\Categories\Models\Category;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 6));
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
});

function purchase(array $overrides = []): array
{
    return ['account_id' => test()->card->id, 'date' => '2026-03-05', 'amount' => 1000, 'direction' => 'out',
        'description' => 'Notebook', 'installments' => 3, ...$overrides];
}

function parcels(): array
{
    return Transaction::query()->with('statement')->orderBy('installment_number')->get()
        ->map(fn (Transaction $t) => [$t->installment_number, $t->amount->cents, $t->date->toDateString(), $t->status->value, $t->statement?->due_date->toDateString()])
        ->all();
}

it('cria o plano e uma parcela por fatura consecutiva', function () {
    $this->postJson('/api/v1/transactions', purchase())->assertCreated()
        ->assertJsonPath('data.installment.number', 1)
        ->assertJsonPath('data.installment.total', 3)
        ->assertJsonPath('data.amount', 334);

    expect(InstallmentPlan::count())->toBe(1)
        ->and(parcels())->toBe([
            [1, 334, '2026-03-05', 'posted', '2026-03-20'],
            [2, 333, '2026-04-05', 'projected', '2026-04-20'],
            [3, 333, '2026-05-05', 'projected', '2026-05-20'],
        ]);
});

it('compra antiga parcelada nasce com as parcelas vencidas já lançadas', function () {
    $this->postJson('/api/v1/transactions', purchase(['date' => '2026-01-05', 'installments' => 4, 'amount' => 4000]))->assertCreated();

    expect(array_column(parcels(), 3))->toBe(['posted', 'posted', 'posted', 'projected']);
});

it('copia categoria, tags e notas para todas as parcelas', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $tag = Tag::factory()->create(['user_id' => $this->user->id]);

    $this->postJson('/api/v1/transactions', purchase(['category_id' => $category->id, 'tag_ids' => [$tag->id], 'notes' => 'n']))->assertCreated();

    Transaction::query()->with('tags')->get()->each(function (Transaction $t) use ($category, $tag) {
        expect($t->category_id)->toBe($category->id)->and($t->notes)->toBe('n')->and($t->tags->pluck('id')->all())->toBe([$tag->id]);
    });
});

it('uma parcela é uma compra comum', function () {
    $this->postJson('/api/v1/transactions', purchase(['installments' => 1]))->assertCreated()->assertJsonPath('data.installment', null);

    expect(InstallmentPlan::count())->toBe(0);
});

it('recusa parcelamento fora de cartão ou em receita', function () {
    $checking = Account::factory()->create(['user_id' => $this->user->id]);

    $this->postJson('/api/v1/transactions', purchase(['account_id' => $checking->id]))->assertStatus(409)->assertJsonPath('code', 'installments_require_card');
    $this->postJson('/api/v1/transactions', purchase(['direction' => 'in']))->assertStatus(409)->assertJsonPath('code', 'installments_require_card');
});

it('valida o número de parcelas', function () {
    $this->postJson('/api/v1/transactions', purchase(['installments' => 49]))->assertUnprocessable()->assertJsonValidationErrors(['installments']);
});

it('recusa valor menor que o número de parcelas', function () {
    $this->postJson('/api/v1/transactions', purchase(['amount' => 2]))->assertStatus(409)->assertJsonPath('code', 'installment_amount_too_small');
});

it('parcela não muda valor, data, conta nem tipo', function () {
    $id = $this->postJson('/api/v1/transactions', purchase())->json('data.id');
    $other = Account::factory()->create(['user_id' => $this->user->id]);

    foreach ([['amount' => 1], ['date' => '2026-03-06'], ['direction' => 'in'], ['account_id' => $other->id]] as $change) {
        $this->patchJson("/api/v1/transactions/{$id}", $change)->assertStatus(409)->assertJsonPath('code', 'installment_locked');
    }
});

it('parcela aceita reenviar os mesmos valores e mudar categoria/descrição', function () {
    $id = $this->postJson('/api/v1/transactions', purchase())->json('data.id');

    $this->patchJson("/api/v1/transactions/{$id}", ['amount' => 334, 'date' => '2026-03-05', 'description' => 'Notebook novo'])
        ->assertOk()->assertJsonPath('data.description', 'Notebook novo');
});

it('excluir uma parcela exclui o parcelamento inteiro', function () {
    $id = $this->postJson('/api/v1/transactions', purchase())->json('data.id');

    $this->deleteJson("/api/v1/transactions/{$id}")->assertNoContent();

    expect(Transaction::count())->toBe(0)->and(InstallmentPlan::count())->toBe(0);
});

it('lista mostra a parcela como n de N', function () {
    $this->postJson('/api/v1/transactions', purchase());

    $this->getJson('/api/v1/transactions')->assertOk()
        ->assertJsonPath('data.0.installment.total', 3);
});

it('com statement_id explícito, as parcelas encadeiam a partir da fatura escolhida, não da data', function () {
    $chosen = app(StatementResolver::class)->forDate($this->card, CarbonImmutable::parse('2026-04-05'));

    $this->postJson('/api/v1/transactions', purchase(['statement_id' => $chosen->id]))->assertCreated();

    expect(array_column(parcels(), 4))->toBe(['2026-04-20', '2026-05-20', '2026-06-20']);
});

it('recusa fatura de outro cartão e não cria o parcelamento', function () {
    $otherCard = Account::factory()->creditCard()->create(['user_id' => $this->user->id]);
    $otherStatement = app(StatementResolver::class)->forDate($otherCard, CarbonImmutable::parse('2026-03-05'));

    $this->postJson('/api/v1/transactions', purchase(['statement_id' => $otherStatement->id]))
        ->assertStatus(409)->assertJsonPath('code', 'statement_account_mismatch');

    expect(InstallmentPlan::count())->toBe(0)->and(Transaction::count())->toBe(0);
});

it('compra no dia do fechamento cai na fatura seguinte', function () {
    $this->postJson('/api/v1/transactions', purchase(['date' => '2026-03-10']))->assertCreated();

    expect(array_column(parcels(), 4))->toBe(['2026-04-20', '2026-05-20', '2026-06-20']);
});

it('compra em 31 de janeiro gera parcelas sem overflow e faturas encadeadas', function () {
    $this->postJson('/api/v1/transactions', purchase(['date' => '2026-01-31']))->assertCreated();

    expect(array_column(parcels(), 2))->toBe(['2026-01-31', '2026-02-28', '2026-03-31'])
        ->and(array_column(parcels(), 4))->toBe(['2026-02-20', '2026-03-20', '2026-04-20']);
});

it('parcela aceita editar is_ignored, fatura, tags e notas', function () {
    $id = $this->postJson('/api/v1/transactions', purchase())->json('data.id');
    $tag = Tag::factory()->create(['user_id' => $this->user->id]);
    $statement = app(StatementResolver::class)->forDate($this->card, CarbonImmutable::parse('2026-04-05'));

    $this->patchJson("/api/v1/transactions/{$id}", [
        'is_ignored' => true,
        'statement_id' => $statement->id,
        'tag_ids' => [$tag->id],
        'notes' => 'nota',
    ])->assertOk()
        ->assertJsonPath('data.is_ignored', true)
        ->assertJsonPath('data.statement_id', $statement->id)
        ->assertJsonPath('data.notes', 'nota');

    expect(Transaction::find($id)->tags->pluck('id')->all())->toBe([$tag->id]);
});

it('excluir uma parcela que não é a primeira exclui o parcelamento, todas as parcelas e os vínculos de tag', function () {
    $tag = Tag::factory()->create(['user_id' => $this->user->id]);
    $this->postJson('/api/v1/transactions', purchase(['tag_ids' => [$tag->id]]))->assertCreated();

    $second = Transaction::query()->where('installment_number', 2)->firstOrFail();

    $this->deleteJson("/api/v1/transactions/{$second->id}")->assertNoContent();

    expect(Transaction::count())->toBe(0)
        ->and(InstallmentPlan::count())->toBe(0)
        ->and(DB::table('tag_transaction')->count())->toBe(0);
});

it('mostra o parcelamento no show e no update', function () {
    $id = $this->postJson('/api/v1/transactions', purchase())->json('data.id');

    $this->getJson("/api/v1/transactions/{$id}")->assertOk()->assertJsonPath('data.installment.total', 3);

    $this->patchJson("/api/v1/transactions/{$id}", ['notes' => 'x'])->assertOk()->assertJsonPath('data.installment.total', 3);
});
