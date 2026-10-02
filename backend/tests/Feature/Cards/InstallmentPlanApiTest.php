<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 6)->startOfDay());
    $this->user = actingAsUser();
    $this->card = Account::factory()->creditCard(closingDay: 10, dueDay: 20)->create(['user_id' => $this->user->id]);
    $this->firstId = $this->postJson('/api/v1/transactions', [
        'account_id' => $this->card->id, 'date' => '2026-02-05', 'amount' => 3000, 'direction' => 'out',
        'description' => 'Fone', 'installments' => 3,
    ])->json('data.id');
    $this->plan = InstallmentPlan::query()->firstOrFail();
});

it('lista os parcelamentos do cartão com progresso', function () {
    $this->getJson("/api/v1/cards/{$this->card->id}/installment-plans")->assertOk()
        ->assertJsonPath('data.0.description', 'Fone')
        ->assertJsonPath('data.0.installments', 3)
        ->assertJsonPath('data.0.installment_amount', 1000)
        ->assertJsonPath('data.0.posted_count', 2)
        ->assertJsonPath('data.0.projected_count', 1)
        ->assertJsonPath('data.0.remaining_amount', 1000)
        ->assertJsonPath('data.0.next_date', '2026-04-05');
});

it('editar o parcelamento muda só as parcelas projetadas', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $this->patchJson("/api/v1/installment-plans/{$this->plan->id}", ['description' => 'Fone BT', 'category_id' => $category->id])
        ->assertOk()->assertJsonPath('data.description', 'Fone BT')->assertJsonPath('data.category_id', $category->id);

    $posted = Transaction::query()->where('status', TransactionStatus::Posted->value)->get();
    $projected = Transaction::query()->where('status', TransactionStatus::Projected->value)->get();
    expect($posted->pluck('description')->unique()->all())->toBe(['Fone'])
        ->and($projected->pluck('description')->unique()->all())->toBe(['Fone BT'])
        ->and($projected->pluck('category_id')->unique()->all())->toBe([$category->id]);
});

it('cancelar exclui só as projetadas e marca o plano', function () {
    $this->deleteJson("/api/v1/installment-plans/{$this->plan->id}")->assertNoContent();

    expect(Transaction::count())->toBe(2)
        ->and($this->plan->fresh()->cancelled_at)->not->toBeNull();

    $this->getJson("/api/v1/cards/{$this->card->id}/installment-plans")->assertJsonPath('data.0.projected_count', 0);
});

it('parcelamento de outro usuário dá 404', function () {
    $other = InstallmentPlan::factory()->create(['account_id' => Account::factory()->creditCard()->create(['user_id' => User::factory()->create()->id])->id]);

    $this->patchJson("/api/v1/installment-plans/{$other->id}", ['description' => 'x'])->assertNotFound();
    $this->deleteJson("/api/v1/installment-plans/{$other->id}")->assertNotFound();
});

it('categoria precisa ser do usuário', function () {
    $foreign = Category::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->patchJson("/api/v1/installment-plans/{$this->plan->id}", ['category_id' => $foreign->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['category_id']);
});

it('limpar a categoria (null) limpa categorized_by nas parcelas projetadas', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $this->patchJson("/api/v1/installment-plans/{$this->plan->id}", ['category_id' => $category->id])->assertOk();

    $this->patchJson("/api/v1/installment-plans/{$this->plan->id}", ['category_id' => null])
        ->assertOk()->assertJsonPath('data.category_id', null);

    $projected = Transaction::query()->where('status', TransactionStatus::Projected->value)->first();
    expect($projected->category_id)->toBeNull()
        ->and($projected->categorized_by)->toBeNull();
});

it('recusa trocar categoria de parcelamento sem parcelas projetadas', function () {
    // Compra iniciada em janeiro, 2 parcelas: ambas já lançadas (hoje é 06/03/2026).
    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->card->id, 'date' => '2026-01-06', 'amount' => 2000, 'direction' => 'out',
        'description' => 'Finished', 'installments' => 2,
    ])->assertCreated();
    $finished = InstallmentPlan::query()->where('description', 'Finished')->firstOrFail();

    $this->patchJson("/api/v1/installment-plans/{$finished->id}", ['category_id' => Category::factory()->create(['user_id' => $this->user->id])->id])
        ->assertStatus(409)->assertJsonPath('code', 'installment_plan_finished');

    // Descrição continua editável mesmo sem parcelas futuras.
    $this->patchJson("/api/v1/installment-plans/{$finished->id}", ['description' => 'Finished (editado)'])
        ->assertOk()->assertJsonPath('data.description', 'Finished (editado)');
});

it('cancelar é idempotente: repetir não muda cancelled_at', function () {
    $this->deleteJson("/api/v1/installment-plans/{$this->plan->id}")->assertNoContent();
    $firstCancelledAt = $this->plan->fresh()->cancelled_at;

    $this->deleteJson("/api/v1/installment-plans/{$this->plan->id}")->assertNoContent();

    expect($this->plan->fresh()->cancelled_at->toIso8601String())->toBe($firstCancelledAt->toIso8601String());
});

it('cancelar mantém intactos os campos das parcelas já lançadas', function () {
    $before = Transaction::query()->where('status', TransactionStatus::Posted->value)->orderBy('installment_number')->get();

    $this->deleteJson("/api/v1/installment-plans/{$this->plan->id}")->assertNoContent();

    $after = Transaction::query()->where('status', TransactionStatus::Posted->value)->orderBy('installment_number')->get();
    expect($after->pluck('description')->all())->toBe($before->pluck('description')->all())
        ->and($after->pluck('amount')->map(fn ($m) => $m->cents)->all())->toBe($before->pluck('amount')->map(fn ($m) => $m->cents)->all())
        ->and($after->pluck('date')->map(fn ($d) => $d->toDateString())->all())->toBe($before->pluck('date')->map(fn ($d) => $d->toDateString())->all());
});

it('cancelar um parcelamento já todo lançado só marca cancelled_at', function () {
    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->card->id, 'date' => '2026-01-06', 'amount' => 2000, 'direction' => 'out',
        'description' => 'Finished', 'installments' => 2,
    ])->assertCreated();
    $finished = InstallmentPlan::query()->where('description', 'Finished')->firstOrFail();
    $countBefore = Transaction::count();

    $this->deleteJson("/api/v1/installment-plans/{$finished->id}")->assertNoContent();

    expect(Transaction::count())->toBe($countBefore)
        ->and($finished->fresh()->cancelled_at)->not->toBeNull();
});

it('não lista parcelamentos de uma conta que não é cartão', function () {
    $checking = Account::factory()->create(['user_id' => $this->user->id]);

    $this->getJson("/api/v1/cards/{$checking->id}/installment-plans")->assertNotFound();
});

it('ordena ativos antes de quitados e cancelados por último, mesmo fora de ordem cronológica', function () {
    // Mais recente dos três, mas não deveria vir primeiro: fica cancelado.
    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->card->id, 'date' => '2026-03-01', 'amount' => 3000, 'direction' => 'out',
        'description' => 'Cancelado', 'installments' => 3,
    ])->assertCreated();
    $cancelled = InstallmentPlan::query()->where('description', 'Cancelado')->firstOrFail();
    $this->deleteJson("/api/v1/installment-plans/{$cancelled->id}")->assertNoContent();

    // Mais antigo dos três, mas ativo tem prioridade sobre quitado.
    $this->postJson('/api/v1/transactions', [
        'account_id' => $this->card->id, 'date' => '2026-01-06', 'amount' => 2000, 'direction' => 'out',
        'description' => 'Finished', 'installments' => 2,
    ])->assertCreated();

    $this->getJson("/api/v1/cards/{$this->card->id}/installment-plans")->assertOk()
        ->assertJsonPath('data.0.description', 'Fone')
        ->assertJsonPath('data.1.description', 'Finished')
        ->assertJsonPath('data.2.description', 'Cancelado');
});
