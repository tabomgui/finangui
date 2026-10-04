<?php

namespace App\Domain\Budgets\Models;

use App\Domain\Categories\Models\Category;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\BudgetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orçamento mensal de uma categoria de despesa: um valor padrão (month nulo)
 * e, opcionalmente, uma exceção para um mês específico. O gasto, o restante
 * e o progresso são sempre derivados (App\Domain\Budgets\Queries\MonthBudget),
 * nunca gravados aqui.
 *
 * @property Money $amount
 * @property CarbonImmutable|null $month
 */
class Budget extends Model
{
    use BelongsToUser;

    /** @use HasFactory<BudgetFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'category_id', 'amount', 'month'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'month' => 'immutable_date',
        ];
    }

    protected static function newFactory(): BudgetFactory
    {
        return BudgetFactory::new();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
