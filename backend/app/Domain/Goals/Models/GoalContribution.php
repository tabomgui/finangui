<?php

namespace App\Domain\Goals\Models;

use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\GoalContributionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aporte (amount positivo) ou retirada (amount negativo) numa meta sem conta
 * vinculada. Metas com conta não aceitam aportes
 * (App\Domain\Goals\Errors\GoalContributionsNotAllowed).
 *
 * @property Money $amount
 * @property CarbonImmutable $date
 */
class GoalContribution extends Model
{
    use BelongsToUser;

    /** @use HasFactory<GoalContributionFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'goal_id', 'amount', 'date', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'date' => 'immutable_date',
        ];
    }

    protected static function newFactory(): GoalContributionFactory
    {
        return GoalContributionFactory::new();
    }

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
