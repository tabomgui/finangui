<?php

namespace App\Domain\Goals\Models;

use App\Domain\Accounts\Models\Account;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Meta de economia. Com conta vinculada, o progresso é o saldo dela
 * (App\Domain\Accounts\Models\Account::scopeWithBalance()); sem conta, a soma
 * dos aportes (goal_contributions) — e só nesse caso a meta aceita aportes.
 * Progresso, restante, percentual e ritmo mensal são sempre derivados
 * (App\Domain\Goals\Support\GoalProgress), nunca gravados aqui. achieved_at é
 * a única exceção: grava quando o progresso atinge o alvo pela primeira vez
 * e nunca é limpo depois (App\Domain\Goals\Actions\RefreshGoalAchievement).
 *
 * @property Money $target_amount
 * @property CarbonImmutable|null $target_date
 * @property CarbonImmutable|null $achieved_at
 */
class Goal extends Model
{
    use BelongsToUser;

    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'target_amount', 'target_date', 'account_id', 'color', 'icon', 'achieved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_amount' => MoneyCast::class,
            'target_date' => 'immutable_date',
            'achieved_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): GoalFactory
    {
        return GoalFactory::new();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<GoalContribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class);
    }
}
