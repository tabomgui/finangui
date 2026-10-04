<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Budgets\Models\Budget;
use App\Domain\Cards\Models\CardStatement;
use App\Domain\Categories\Models\Category;
use App\Domain\Notifications\Jobs\SendAlerts;
use App\Domain\Recurrences\Actions\GenerateOccurrences;
use App\Domain\Recurrences\Models\Recurrence;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

function runSendAlerts(): void
{
    app()->call([new SendAlerts, 'handle']);
}

/**
 * @return Collection<int, DatabaseNotification>
 */
function notificationsOf(User $user): Collection
{
    return DatabaseNotification::query()
        ->where('notifiable_type', User::class)->where('notifiable_id', $user->id)
        ->get();
}

function chargedStatement(Account $card, string $closingDate, string $dueDate, bool $paid = false): CardStatement
{
    $statement = CardStatement::factory()->create([
        'account_id' => $card->id, 'closing_date' => $closingDate, 'due_date' => $dueDate,
    ]);

    Transaction::factory()->create([
        'account_id' => $card->id, 'statement_id' => $statement->id, 'amount' => 10000, 'direction' => Direction::Out,
    ]);

    if ($paid) {
        Transaction::factory()->create([
            'account_id' => $card->id, 'statement_id' => $statement->id, 'amount' => 10000,
            'direction' => Direction::In, 'transfer_id' => (string) Str::uuid(),
        ]);
    }

    return $statement;
}

it('alerta fatura não paga vencendo hoje, amanhã e em 3 dias, mas não em 4 dias nem paga', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $user = User::factory()->create();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);

    $today = chargedStatement($card, '2026-03-03', '2026-03-10');
    $tomorrow = chargedStatement($card, '2026-03-04', '2026-03-11');
    $in3Days = chargedStatement($card, '2026-03-06', '2026-03-13');
    chargedStatement($card, '2026-03-07', '2026-03-14'); // em 4 dias: fora da janela
    chargedStatement($card, '2026-03-05', '2026-03-12', paid: true); // paga: não alerta

    runSendAlerts();

    $bodies = notificationsOf($user)->pluck('data')->pluck('body')->all();
    expect($bodies)->toHaveCount(3)
        ->and($bodies)->toContain("Fatura do {$card->name} vence hoje.")
        ->and($bodies)->toContain("Fatura do {$card->name} vence amanhã.")
        ->and($bodies)->toContain("Fatura do {$card->name} vence em 3 dias.");

    $dueTodayNotification = notificationsOf($user)->firstWhere('data.key', "statement_due:{$today->id}");
    expect($dueTodayNotification->data)->toMatchArray([
        'type' => 'statement_due',
        'url' => "/cartoes/{$card->id}?fatura={$today->id}",
    ]);

    expect($tomorrow->id)->not->toBeNull() // só para silenciar "variável não usada"
        ->and($in3Days->id)->not->toBeNull();
});

it('cria alerta de orçamento estourado do mês atual', function () {
    CarbonImmutable::setTestNow('2026-10-15');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Lazer']);
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 20000]);
    Transaction::factory()->create([
        'account_id' => $account->id, 'category_id' => $category->id, 'date' => '2026-10-05', 'amount' => 25000,
    ]);

    runSendAlerts();

    $notification = notificationsOf($user)->firstWhere('data.type', 'budget_exceeded');
    expect($notification)->not->toBeNull()
        ->and($notification->data['key'])->toBe("budget_exceeded:{$category->id}:2026-10")
        ->and($notification->data['body'])->toBe('Orçamento de Lazer estourado.')
        ->and($notification->data['url'])->toBe('/orcamento');
});

it('não alerta orçamento dentro do limite', function () {
    CarbonImmutable::setTestNow('2026-10-15');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 20000]);
    Transaction::factory()->create([
        'account_id' => $account->id, 'category_id' => $category->id, 'date' => '2026-10-05', 'amount' => 10000,
    ]);

    runSendAlerts();

    expect(notificationsOf($user)->firstWhere('data.type', 'budget_exceeded'))->toBeNull();
});

it('cria um único alerta agregado para as previstas atrasadas do dia', function () {
    CarbonImmutable::setTestNow('2026-03-01');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $recurrence = Recurrence::factory()->create([
        'account_id' => $account->id, 'user_id' => $user->id, 'day_of_month' => 5, 'starts_on' => '2026-01-05',
    ]);

    UserContext::run($user, fn () => app(GenerateOccurrences::class)->handle($recurrence->refresh()));

    // Gera 2026-01-05 e 2026-02-05; hoje - 5 dias precisa passar de ambas.
    CarbonImmutable::setTestNow('2026-02-12');

    runSendAlerts();

    $notification = notificationsOf($user)->firstWhere('data.type', 'occurrence_overdue');
    expect($notification)->not->toBeNull()
        ->and($notification->data['key'])->toBe('occurrence_overdue:2026-02-12')
        ->and($notification->data['body'])->toBe('2 lançamentos previstos não confirmados.');
});

it('usa o singular quando é só um lançamento previsto atrasado', function () {
    CarbonImmutable::setTestNow('2026-03-01');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $recurrence = Recurrence::factory()->create([
        'account_id' => $account->id, 'user_id' => $user->id, 'day_of_month' => 5,
        'starts_on' => '2026-01-05', 'ends_on' => '2026-01-05',
    ]);

    UserContext::run($user, fn () => app(GenerateOccurrences::class)->handle($recurrence->refresh()));

    CarbonImmutable::setTestNow('2026-02-12');

    runSendAlerts();

    $notification = notificationsOf($user)->firstWhere('data.type', 'occurrence_overdue');
    expect($notification->data['body'])->toBe('1 lançamento previsto não confirmado.');
});

it('marca como lida a notificação de previstos atrasados de um dia anterior ao criar a de hoje', function () {
    CarbonImmutable::setTestNow('2026-03-01');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $recurrence = Recurrence::factory()->create([
        'account_id' => $account->id, 'user_id' => $user->id, 'day_of_month' => 5, 'starts_on' => '2026-01-05',
    ]);

    UserContext::run($user, fn () => app(GenerateOccurrences::class)->handle($recurrence->refresh()));

    CarbonImmutable::setTestNow('2026-02-12');
    runSendAlerts();
    $yesterday = notificationsOf($user)->firstWhere('data.type', 'occurrence_overdue');
    expect($yesterday->read_at)->toBeNull();

    CarbonImmutable::setTestNow('2026-02-13');
    runSendAlerts();

    expect($yesterday->refresh()->read_at)->not->toBeNull();
    $today = notificationsOf($user)->firstWhere('data.key', 'occurrence_overdue:2026-02-13');
    expect($today->read_at)->toBeNull();
});

it('orçamento estourado de um mês anterior não alerta no mês atual', function () {
    CarbonImmutable::setTestNow('2026-10-15');
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    Budget::factory()->create(['category_id' => $category->id, 'amount' => 20000]);
    // Setembro: estourou, mas é mês passado.
    Transaction::factory()->create([
        'account_id' => $account->id, 'category_id' => $category->id, 'date' => '2026-09-05', 'amount' => 50000,
    ]);
    // Outubro (mês atual): dentro do limite.
    Transaction::factory()->create([
        'account_id' => $account->id, 'category_id' => $category->id, 'date' => '2026-10-05', 'amount' => 5000,
    ]);

    runSendAlerts();

    expect(notificationsOf($user)->firstWhere('data.type', 'budget_exceeded'))->toBeNull();
});

it('é idempotente: rodar duas vezes não duplica nenhum alerta', function () {
    CarbonImmutable::setTestNow('2026-03-10');
    $user = User::factory()->create();
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);
    chargedStatement($card, '2026-03-03', '2026-03-10');

    runSendAlerts();
    $firstRunCount = notificationsOf($user)->count();

    runSendAlerts();
    $secondRunCount = notificationsOf($user)->count();

    expect($firstRunCount)->toBe(1)
        ->and($secondRunCount)->toBe(1);
});

it('isola a falha de um usuário: os demais continuam recebendo seus alertas', function () {
    Exceptions::fake();
    CarbonImmutable::setTestNow('2026-03-10');

    $broken = User::factory()->create();
    $brokenCategory = Category::factory()->create(['user_id' => $broken->id]);
    // Só gravável direto na coluna: o cast de kind lança ao ser lido por
    // MonthBudget::for() (que itera toda categoria do usuário).
    DB::table('categories')->where('id', $brokenCategory->id)->update(['kind' => 'invalid']);

    $ok = User::factory()->create();
    $card = Account::factory()->creditCard()->create(['user_id' => $ok->id]);
    chargedStatement($card, '2026-03-03', '2026-03-10');

    runSendAlerts();

    expect(notificationsOf($broken))->toHaveCount(0)
        ->and(notificationsOf($ok))->toHaveCount(1);

    Exceptions::assertReportedCount(1);
});

it('apaga notificações lidas com mais de 90 dias, mas preserva as recentes e as não lidas', function () {
    $user = User::factory()->create();

    $old = $user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'test',
        'data' => ['type' => 'occurrence_overdue', 'key' => 'k1', 'title' => 't', 'body' => 'b', 'url' => '/'],
    ]);
    $old->forceFill(['read_at' => now()->subDays(100)])->save();

    $recent = $user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'test',
        'data' => ['type' => 'occurrence_overdue', 'key' => 'k2', 'title' => 't', 'body' => 'b', 'url' => '/'],
    ]);
    $recent->forceFill(['read_at' => now()->subDays(10)])->save();

    $unread = $user->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'test',
        'data' => ['type' => 'occurrence_overdue', 'key' => 'k3', 'title' => 't', 'body' => 'b', 'url' => '/'],
    ]);
    DB::table('notifications')->where('id', $old->id)->update(['created_at' => now()->subDays(100)]);

    runSendAlerts();

    $remainingIds = notificationsOf($user)->pluck('id')->all();
    expect($remainingIds)->not->toContain($old->id)
        ->and($remainingIds)->toContain($recent->id)
        ->and($remainingIds)->toContain($unread->id);
});

it('está agendado para rodar todo dia às 07:00', function () {
    app(Kernel::class)->bootstrap();

    $events = app(Schedule::class)->events();

    $event = collect($events)->first(fn ($event) => $event->description === SendAlerts::class);
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 7 * * *');
});
