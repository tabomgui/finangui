<?php

namespace App\Domain\Cards\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Transactions\Models\Transaction;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\InstallmentPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compra parcelada em N vezes: uma transação por fatura consecutiva.
 *
 * @property Money $total_amount
 * @property CarbonImmutable $purchase_date
 * @property CarbonImmutable|null $cancelled_at
 * @property-read int|null $posted_count carregado por InstallmentPlanList (withCount)
 * @property-read int|null $projected_count carregado por InstallmentPlanList (withCount)
 * @property-read int|null $remaining_amount carregado por InstallmentPlanList (withSum)
 * @property-read string|null $next_date carregado por InstallmentPlanList (withMin)
 * @property-read int|null $category_id carregado por InstallmentPlanList (addSelect)
 */
class InstallmentPlan extends Model
{
    use BelongsToUser;

    /** @use HasFactory<InstallmentPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'account_id', 'description', 'total_amount', 'installments',
        'purchase_date', 'fingerprint', 'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_amount' => MoneyCast::class,
            'installments' => 'integer',
            'purchase_date' => 'immutable_date',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): InstallmentPlanFactory
    {
        return InstallmentPlanFactory::new();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'installment_plan_id')->orderBy('installment_number');
    }
}
