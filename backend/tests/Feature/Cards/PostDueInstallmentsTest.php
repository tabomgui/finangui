<?php

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Jobs\PostDueInstallments;
use App\Domain\Cards\Models\InstallmentPlan;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

function projectedParcel(User $user, string $date, bool $installment = true): Transaction
{
    $card = Account::factory()->creditCard()->create(['user_id' => $user->id]);
    $plan = $installment ? InstallmentPlan::factory()->create(['account_id' => $card->id]) : null;

    return Transaction::factory()->create([
        'account_id' => $card->id, 'date' => $date, 'status' => TransactionStatus::Projected,
        'installment_plan_id' => $plan?->id, 'installment_number' => $installment ? 2 : null,
    ]);
}

it('lança as parcelas projetadas cuja data chegou, de todos os usuários', function () {
    $this->travelTo(now()->setDate(2026, 4, 5));
    $a = projectedParcel(User::factory()->create(), '2026-04-05');
    $b = projectedParcel(User::factory()->create(), '2026-04-01');
    $future = projectedParcel(User::factory()->create(), '2026-04-06');

    (new PostDueInstallments)->handle();

    $status = fn (Transaction $t) => Transaction::query()->withoutGlobalScopes()->findOrFail($t->id)->status;
    expect($status($a))->toBe(TransactionStatus::Posted)
        ->and($status($b))->toBe(TransactionStatus::Posted)
        ->and($status($future))->toBe(TransactionStatus::Projected)
        ->and(Auth::hasUser())->toBeFalse();
});

it('não mexe em projetadas que não são parcela', function () {
    $this->travelTo(now()->setDate(2026, 4, 5));
    $other = projectedParcel(User::factory()->create(), '2026-04-01', installment: false);

    (new PostDueInstallments)->handle();

    expect(Transaction::query()->withoutGlobalScopes()->findOrFail($other->id)->status)->toBe(TransactionStatus::Projected);
});
