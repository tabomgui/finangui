<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Tags\Models\Tag;
use App\Domain\Transactions\Models\Transaction;

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

    foreach ([['amount' => 1], ['date' => '2026-03-06'], ['direction' => 'in']] as $change) {
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
