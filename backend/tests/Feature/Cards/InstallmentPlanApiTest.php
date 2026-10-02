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
