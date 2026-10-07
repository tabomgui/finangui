<?php

namespace App\Domain\Banking\Models;

use App\Domain\Transactions\Enums\Direction;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\BankSyncRunItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um lançamento adicionado por uma App\Domain\Banking\Models\BankSyncRun —
 * snapshot (`account_name`, `date`, `description`, `amount`, `direction`)
 * para o histórico continuar legível mesmo que a transação seja apagada
 * depois (`transaction_id` nullOnDelete). Limitado a 500 itens por run — ver
 * App\Domain\Banking\Jobs\SyncConnection; acima disso só `added_count` conta.
 *
 * @property CarbonImmutable $date
 * @property Money $amount
 * @property Direction $direction
 */
class BankSyncRunItem extends Model
{
    use BelongsToUser;

    /** @use HasFactory<BankSyncRunItemFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'run_id', 'transaction_id', 'account_name', 'date', 'description', 'amount', 'direction',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'amount' => MoneyCast::class,
            'direction' => Direction::class,
        ];
    }

    protected static function newFactory(): BankSyncRunItemFactory
    {
        return BankSyncRunItemFactory::new();
    }

    /**
     * @return BelongsTo<BankSyncRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(BankSyncRun::class, 'run_id');
    }
}
