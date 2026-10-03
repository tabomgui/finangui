<?php

namespace App\Domain\Cards\Models;

use App\Domain\Accounts\Models\Account;
use App\Domain\Cards\Enums\StatementStatus;
use App\Domain\Transactions\Enums\Direction;
use App\Domain\Transactions\Models\Transaction;
use App\Models\Concerns\BelongsToUser;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\CardStatementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Fatura de cartão. Só datas (reais e editáveis) e o total informado pelo
 * banco são gravados; total, pago, restante e status são sempre derivados das
 * transações ligadas (scopeWithTotals).
 *
 * @property CarbonImmutable $closing_date
 * @property CarbonImmutable $due_date
 * @property Money|null $reported_total
 */
class CardStatement extends Model
{
    use BelongsToUser;

    /** @use HasFactory<CardStatementFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'account_id', 'closing_date', 'due_date', 'external_id', 'reported_total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closing_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'reported_total' => MoneyCast::class,
        ];
    }

    protected static function newFactory(): CardStatementFactory
    {
        return CardStatementFactory::new();
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
        return $this->hasMany(Transaction::class, 'statement_id');
    }

    /**
     * Carrega cobranças líquidas (saídas − estornos) e pagamentos (entradas que
     * são perna de transferência) numa única query. Considera todo status,
     * inclusive parcelas projetadas; ignora transações marcadas como ignoradas.
     *
     * @param  Builder<CardStatement>  $query
     */
    public function scopeWithTotals(Builder $query): void
    {
        $linked = fn () => Transaction::query()->withoutGlobalScopes()
            ->whereColumn('transactions.statement_id', 'card_statements.id')
            ->where('transactions.is_ignored', false);

        $query->select('card_statements.*')->addSelect([
            'charges_net' => $linked()->selectRaw(
                "COALESCE(SUM(CASE WHEN transactions.direction = 'out' THEN transactions.amount WHEN transactions.transfer_id IS NULL THEN -transactions.amount ELSE 0 END), 0)"
            ),
            'payments_sum' => $linked()
                ->where('transactions.direction', Direction::In->value)
                ->whereNotNull('transactions.transfer_id')
                ->selectRaw('COALESCE(SUM(transactions.amount), 0)'),
        ]);
    }

    public function total(): Money
    {
        return Money::cents((int) $this->loadedTotal('charges_net'));
    }

    public function paid(): Money
    {
        return Money::cents((int) $this->loadedTotal('payments_sum'));
    }

    public function remaining(): Money
    {
        return Money::cents(max($this->total()->cents - $this->paid()->cents, 0));
    }

    public function status(CarbonImmutable $today): StatementStatus
    {
        if ($today->startOfDay()->lessThan($this->closing_date)) {
            return StatementStatus::Open;
        }

        $total = $this->total()->cents;
        $paid = $this->paid()->cents;

        return match (true) {
            $total <= 0 || $paid >= $total => StatementStatus::Paid,
            $paid > 0 => StatementStatus::Partial,
            default => StatementStatus::Closed,
        };
    }

    /**
     * Exclui as faturas futuras (closing_date > hoje) do cartão que não têm
     * nenhum lançamento: refletiam um ciclo ou parcelamento que não existe
     * mais (dias do cartão mudaram, ou um parcelamento foi cancelado/excluído).
     * Faturas passadas, mesmo vazias, ficam — são histórico. Uma fatura com
     * total informado ou external_id (importada/editada à mão) também fica:
     * mesmo sem lançamento, carrega um dado que o usuário colocou de propósito.
     */
    public static function pruneEmptyFuture(int $accountId): void
    {
        static::query()
            ->where('account_id', $accountId)
            ->where('closing_date', '>', CarbonImmutable::today()->toDateString())
            ->whereNull('reported_total')
            ->whereNull('external_id')
            ->whereDoesntHave('transactions')
            ->delete();
    }

    private function loadedTotal(string $key): mixed
    {
        if (! array_key_exists($key, $this->attributes)) {
            throw new LogicException('Carregue a fatura com withTotals() antes de ler os totais.');
        }

        return $this->attributes[$key];
    }
}
