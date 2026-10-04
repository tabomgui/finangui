<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Categories\Models\Category;
use App\Domain\Recurrences\Actions\GenerateOccurrences;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = actingAsUser();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);
});

function validRecurrencePayload(array $overrides = []): array
{
    return array_merge([
        'account_id' => test()->account->id,
        'category_id' => test()->category->id,
        'description' => 'Aluguel',
        'amount' => 150000,
        'direction' => 'out',
        'frequency' => 'monthly',
        'day_of_month' => 5,
        'starts_on' => '2026-01-05',
    ], $overrides);
}

it('cria uma recorrência e já gera as ocorrências previstas', function () {
    CarbonImmutable::setTestNow('2026-01-10');

    $response = $this->postJson('/api/v1/recurrences', validRecurrencePayload())->assertCreated();

    $data = $response->json('data');
    expect($data['description'])->toBe('Aluguel')
        ->and($data['amount'])->toBe(150000)
        ->and($data['direction'])->toBe('out')
        ->and($data['frequency'])->toBe('monthly')
        ->and($data['day_of_month'])->toBe(5)
        ->and($data['is_active'])->toBeTrue()
        ->and($data['account']['id'])->toBe($this->account->id)
        ->and($data['category']['id'])->toBe($this->category->id)
        ->and($data['generated_until'])->toBe('2026-02-28');

    expect(Transaction::query()->where('recurrence_id', $data['id'])->count())->toBe(2);
});

it('aceita day_of_month ausente e usa o dia de starts_on como padrão', function () {
    CarbonImmutable::setTestNow('2026-01-10');

    $data = $this->postJson('/api/v1/recurrences', validRecurrencePayload(['day_of_month' => null]))
        ->assertCreated()->json('data');

    expect($data['day_of_month'])->toBe(5);
});

it('omite next_date e category quando não há', function () {
    CarbonImmutable::setTestNow('2026-01-10');

    $data = $this->postJson('/api/v1/recurrences', validRecurrencePayload(['category_id' => null]))
        ->assertCreated()->json('data');

    expect($data)->not->toHaveKey('category')
        ->and($data)->not->toHaveKey('next_date');
});

it('cria a partir de um lançamento existente, herdando os campos e virando a primeira ocorrência', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $transaction = Transaction::factory()->create([
        'account_id' => $this->account->id,
        'date' => '2026-03-01',
        'amount' => 9900,
        'direction' => Direction::Out,
        'description' => 'Spotify',
        'status' => TransactionStatus::Posted,
    ]);

    $data = $this->postJson('/api/v1/recurrences', [
        'transaction_id' => $transaction->id,
        'frequency' => 'monthly',
    ])->assertCreated()->json('data');

    expect($data['description'])->toBe('Spotify')
        ->and($data['amount'])->toBe(9900)
        ->and($data['direction'])->toBe('out')
        ->and($data['starts_on'])->toBe('2026-03-01')
        ->and($data['day_of_month'])->toBe(1);

    expect($transaction->refresh()->recurrence_id)->toBe($data['id'])
        ->and($transaction->recurrence_date->toDateString())->toBe('2026-03-01')
        ->and($transaction->status)->toBe(TransactionStatus::Posted);

    // Só mais uma ocorrência prevista (abril): a de março é a própria transação.
    expect(Transaction::query()->where('recurrence_id', $data['id'])->where('status', 'projected')->count())->toBe(1);
});

it('409 quando a transação de origem não é elegível', function () {
    $transfer = Transaction::factory()->create(['account_id' => $this->account->id, 'transfer_id' => (string) Str::uuid()]);
    $pending = Transaction::factory()->create(['account_id' => $this->account->id, 'status' => TransactionStatus::Pending]);

    $this->postJson('/api/v1/recurrences', ['transaction_id' => $transfer->id, 'frequency' => 'monthly'])
        ->assertStatus(409)->assertJsonPath('code', 'recurrence_transaction_ineligible');

    $this->postJson('/api/v1/recurrences', ['transaction_id' => $pending->id, 'frequency' => 'monthly'])
        ->assertStatus(409)->assertJsonPath('code', 'recurrence_transaction_ineligible');
});

it('422 quando falta campo obrigatório sem transaction_id', function () {
    $this->postJson('/api/v1/recurrences', ['frequency' => 'monthly'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['account_id', 'description', 'amount', 'direction', 'starts_on']);
});

it('422 quando conta ou categoria são de outro usuário', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $otherCategory = Category::factory()->create(['user_id' => $other->id]);

    $this->postJson('/api/v1/recurrences', validRecurrencePayload(['account_id' => $otherAccount->id]))
        ->assertStatus(422)->assertJsonValidationErrors('account_id');

    $this->postJson('/api/v1/recurrences', validRecurrencePayload(['category_id' => $otherCategory->id]))
        ->assertStatus(422)->assertJsonValidationErrors('category_id');
});

it('422 quando a categoria não é compatível com a direção', function () {
    $incomeCategory = Category::factory()->income()->create(['user_id' => $this->user->id]);

    $this->postJson('/api/v1/recurrences', validRecurrencePayload(['direction' => 'out', 'category_id' => $incomeCategory->id]))
        ->assertStatus(422)->assertJsonValidationErrors('category_id');

    $this->postJson('/api/v1/recurrences', validRecurrencePayload(['direction' => 'in', 'category_id' => $this->category->id]))
        ->assertStatus(422)->assertJsonValidationErrors('category_id');
});

it('422 quando day_of_month vem fora da frequência mensal', function () {
    $this->postJson('/api/v1/recurrences', validRecurrencePayload(['frequency' => 'weekly', 'day_of_month' => 10]))
        ->assertStatus(422)->assertJsonValidationErrors('day_of_month');
});

it('422 quando ends_on vem antes de starts_on', function () {
    $this->postJson('/api/v1/recurrences', validRecurrencePayload(['ends_on' => '2026-01-01']))
        ->assertStatus(422)->assertJsonValidationErrors('ends_on');
});

it('lista com ativas primeiro e depois por próxima data', function () {
    CarbonImmutable::setTestNow('2026-03-10');

    $later = Recurrence::factory()->create(['account_id' => $this->account->id, 'starts_on' => '2026-03-20', 'day_of_month' => 20]);
    $sooner = Recurrence::factory()->create(['account_id' => $this->account->id, 'starts_on' => '2026-03-12', 'day_of_month' => 12]);
    $paused = Recurrence::factory()->inactive()->create(['account_id' => $this->account->id, 'starts_on' => '2026-01-01', 'day_of_month' => 1]);

    app(GenerateOccurrences::class)->handle($later);
    app(GenerateOccurrences::class)->handle($sooner);

    $ids = $this->getJson('/api/v1/recurrences')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$sooner->id, $later->id, $paused->id]);
});

it('404 para recorrência de outro usuário em GET/PATCH/DELETE', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $recurrence = Recurrence::factory()->create(['account_id' => $otherAccount->id, 'user_id' => $other->id]);

    $this->getJson("/api/v1/recurrences/{$recurrence->id}")->assertNotFound();
    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['description' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/recurrences/{$recurrence->id}")->assertNotFound();
});

it('PATCH de campo simples atualiza só as previstas futuras, não as passadas nem as lançadas', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    // Lança manualmente a prevista de março (passa a ser "posted").
    Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-03-05')
        ->update(['status' => 'posted']);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['description' => 'Aluguel novo'])
        ->assertOk()->assertJsonPath('data.description', 'Aluguel novo');

    $future = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-04-05')->first();
    $posted = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-03-05')->first();
    $past = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-02-05')->first();

    expect($future->description)->toBe('Aluguel novo')
        ->and($posted->description)->not->toBe('Aluguel novo')
        ->and($past->description)->not->toBe('Aluguel novo');
});

it('PATCH de calendário exclui as previstas futuras e gera de novo', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['day_of_month' => 20])
        ->assertOk()->assertJsonPath('data.day_of_month', 20);

    $dates = Transaction::query()->where('recurrence_id', $recurrence->id)->orderBy('recurrence_date')
        ->pluck('recurrence_date')->map(fn ($d) => $d->toDateString())->all();

    expect($dates)->toBe(['2026-01-05', '2026-02-05', '2026-03-05', '2026-03-20', '2026-04-20']);
});

it('PATCH pausando exclui as previstas futuras; reativar gera de novo a partir de hoje', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);

    expect(Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '>=', '2026-03-10')->count())->toBe(0);

    CarbonImmutable::setTestNow('2026-03-15');
    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);

    $dates = Transaction::query()->where('recurrence_id', $recurrence->id)->orderBy('recurrence_date')
        ->pluck('recurrence_date')->map(fn ($d) => $d->toDateString())->all();

    expect($dates)->toBe(['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05']);
});

it('PATCH não aceita mudar direction', function () {
    $recurrence = Recurrence::factory()->create(['account_id' => $this->account->id, 'direction' => Direction::Out]);

    $this->patchJson("/api/v1/recurrences/{$recurrence->id}", ['direction' => 'in'])->assertOk();

    expect($recurrence->refresh()->direction)->toBe(Direction::Out);
});

it('exclui o modelo: remove as previstas e mantém as já lançadas sem recurrence_id', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $recurrence = Recurrence::factory()->create([
        'account_id' => $this->account->id, 'starts_on' => '2026-01-05', 'day_of_month' => 5,
    ]);
    app(GenerateOccurrences::class)->handle($recurrence);

    $posted = Transaction::query()->where('recurrence_id', $recurrence->id)->where('recurrence_date', '2026-01-05')->first();
    $posted->update(['status' => 'posted']);

    $this->deleteJson("/api/v1/recurrences/{$recurrence->id}")->assertNoContent();

    expect(Recurrence::query()->count())->toBe(0)
        ->and(Transaction::query()->where('id', $posted->id)->first()->recurrence_id)->toBeNull()
        ->and(Transaction::query()->where('status', 'projected')->count())->toBe(0);
});
