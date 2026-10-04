<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
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

it('omite category quando não há', function () {
    CarbonImmutable::setTestNow('2026-01-10');

    $data = $this->postJson('/api/v1/recurrences', validRecurrencePayload(['category_id' => null]))
        ->assertCreated()->json('data');

    expect($data)->not->toHaveKey('category');
});

it('omite next_date quando ainda não há ocorrência prevista gerada', function () {
    CarbonImmutable::setTestNow('2026-01-10');

    // starts_on bem no futuro: fora da janela de geração (até o fim do próximo mês).
    $data = $this->postJson('/api/v1/recurrences', validRecurrencePayload(['starts_on' => '2027-01-05']))
        ->assertCreated()->json('data');

    expect($data)->not->toHaveKey('next_date');
});

it('inclui next_date no show, no create e no update', function () {
    CarbonImmutable::setTestNow('2026-01-10');

    $created = $this->postJson('/api/v1/recurrences', validRecurrencePayload())
        ->assertCreated()->json('data');

    // starts_on (2026-01-05) já passou; a próxima prevista a partir de hoje (2026-01-10) é a de fevereiro.
    expect($created)->toHaveKey('next_date')
        ->and($created['next_date'])->toBe('2026-02-05');

    $shown = $this->getJson("/api/v1/recurrences/{$created['id']}")->assertOk()->json('data');
    expect($shown['next_date'])->toBe('2026-02-05');

    $updated = $this->patchJson("/api/v1/recurrences/{$created['id']}", ['description' => 'Aluguel novo'])
        ->assertOk()->json('data');
    expect($updated['next_date'])->toBe('2026-02-05');
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

it('não preenche o passado: starts_on antigo não gera as ocorrências entre ele e hoje', function () {
    CarbonImmutable::setTestNow('2026-06-10');

    $data = $this->postJson('/api/v1/recurrences', validRecurrencePayload(['starts_on' => '2026-01-05']))
        ->assertCreated()->json('data');

    $dates = Transaction::query()->where('recurrence_id', $data['id'])
        ->orderBy('recurrence_date')->pluck('recurrence_date')->map(fn ($d) => $d->toDateString())->all();

    // Nada de janeiro a maio: a primeira prevista cai dentro da janela hoje - 5 dias.
    expect($dates)->not->toContain('2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05', '2026-05-05')
        ->and($dates)->not->toBeEmpty();
});

it('cria recorrência semanal e anual via API', function () {
    CarbonImmutable::setTestNow('2026-03-10');

    $weekly = $this->postJson('/api/v1/recurrences', validRecurrencePayload([
        'frequency' => 'weekly', 'day_of_month' => null, 'starts_on' => '2026-03-09', 'interval' => 2,
    ]))->assertCreated()->json('data');
    expect($weekly['frequency'])->toBe('weekly')
        ->and($weekly['day_of_month'])->toBeNull();

    $yearly = $this->postJson('/api/v1/recurrences', validRecurrencePayload([
        'frequency' => 'yearly', 'day_of_month' => null, 'starts_on' => '2025-03-15',
    ]))->assertCreated()->json('data');
    expect($yearly['frequency'])->toBe('yearly');
});

it('409 quando a transação de origem é uma parcela', function () {
    $plan = InstallmentPlan::factory()->create(['user_id' => $this->user->id]);
    $installment = Transaction::factory()->create([
        'account_id' => $plan->account_id, 'installment_plan_id' => $plan->id, 'installment_number' => 1,
        'status' => TransactionStatus::Posted,
    ]);

    $this->postJson('/api/v1/recurrences', ['transaction_id' => $installment->id, 'frequency' => 'monthly'])
        ->assertStatus(409)->assertJsonPath('code', 'recurrence_transaction_ineligible');
});

it('422 quando transaction_id é de outro usuário', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $otherTransaction = Transaction::factory()->create(['account_id' => $otherAccount->id, 'user_id' => $other->id]);

    $this->postJson('/api/v1/recurrences', ['transaction_id' => $otherTransaction->id, 'frequency' => 'monthly'])
        ->assertStatus(422)->assertJsonValidationErrors('transaction_id');
});

it('422 quando account_id, direction ou starts_on contradizem a transação de origem', function () {
    $otherAccount = Account::factory()->create(['user_id' => $this->user->id]);
    $transaction = Transaction::factory()->create([
        'account_id' => $this->account->id, 'date' => '2026-03-01', 'direction' => Direction::Out, 'status' => TransactionStatus::Posted,
    ]);

    $this->postJson('/api/v1/recurrences', [
        'transaction_id' => $transaction->id, 'frequency' => 'monthly', 'account_id' => $otherAccount->id,
    ])->assertStatus(422)->assertJsonValidationErrors('account_id');

    $this->postJson('/api/v1/recurrences', [
        'transaction_id' => $transaction->id, 'frequency' => 'monthly', 'direction' => 'in',
    ])->assertStatus(422)->assertJsonValidationErrors('direction');

    $this->postJson('/api/v1/recurrences', [
        'transaction_id' => $transaction->id, 'frequency' => 'monthly', 'starts_on' => '2026-03-02',
    ])->assertStatus(422)->assertJsonValidationErrors('starts_on');
});

it('aceita transaction_id com account_id/direction/starts_on repetindo os mesmos valores da transação', function () {
    $transaction = Transaction::factory()->create([
        'account_id' => $this->account->id, 'date' => '2026-03-01', 'direction' => Direction::Out, 'status' => TransactionStatus::Posted,
    ]);

    $this->postJson('/api/v1/recurrences', [
        'transaction_id' => $transaction->id, 'frequency' => 'monthly',
        'account_id' => $this->account->id, 'direction' => 'out', 'starts_on' => '2026-03-01',
    ])->assertCreated();
});

it('não preenche o passado em conta de cartão: fatura já paga permanece paga', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $checking = Account::factory()->create(['user_id' => $this->user->id]);
    $statement = CardStatement::factory()->create(['account_id' => $card->id, 'closing_date' => '2026-01-10', 'due_date' => '2026-01-20']);
    Transaction::factory()->create(['account_id' => $card->id, 'statement_id' => $statement->id, 'amount' => 10000, 'date' => '2026-01-05']);

    $this->postJson("/api/v1/card-statements/{$statement->id}/payments", [
        'from_account_id' => $checking->id, 'amount' => 10000, 'date' => '2026-01-08',
    ])->assertCreated();
    $this->getJson("/api/v1/card-statements/{$statement->id}")->assertJsonPath('data.status', 'paid');

    $this->postJson('/api/v1/recurrences', [
        'account_id' => $card->id, 'description' => 'Assinatura', 'amount' => 5000, 'direction' => 'out',
        'frequency' => 'monthly', 'day_of_month' => 5, 'starts_on' => '2026-01-05',
    ])->assertCreated();

    expect(Transaction::query()->where('account_id', $card->id)->where('status', 'projected')->where('date', '<', '2026-03-10')->exists())->toBeFalse();
    $this->getJson("/api/v1/card-statements/{$statement->id}")->assertJsonPath('data.status', 'paid');
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

it('422 quando match_pattern não tem nenhuma letra', function () {
    // Um padrão só com dígitos/pontuação normaliza para vazio em
    // TextNormalizer::key() e, sem essa validação, casaria qualquer
    // descrição em RecurrenceMatcher.
    $this->postJson('/api/v1/recurrences', validRecurrencePayload(['match_pattern' => '12-34']))
        ->assertStatus(422)->assertJsonValidationErrors('match_pattern');
});

it('aceita match_pattern com pelo menos uma letra', function () {
    CarbonImmutable::setTestNow('2026-01-10');

    $this->postJson('/api/v1/recurrences', validRecurrencePayload(['match_pattern' => 'Netflix']))
        ->assertCreated()
        ->assertJsonPath('data.match_pattern', 'Netflix');
});
