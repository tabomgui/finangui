<?php

namespace App\Domain\Banking\Models;

use App\Domain\Banking\Enums\SyncRunStatus;
use App\Domain\Banking\Enums\SyncTrigger;
use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonImmutable;
use Database\Factories\BankSyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma execução de App\Domain\Banking\Jobs\SyncConnection: o que disparou
 * (`trigger`), como terminou (`status`, `error`) e o que mudou (`added_count`,
 * `updated_count`, `bills_count`, `warnings`, `stats`) — histórico de
 * sincronização visível ao usuário (`GET /bank-connections/{id}/sync-runs`,
 * `GET /bank-sync-runs/{id}`).
 *
 * Criada no início da execução (`status = running`) e fechada no fim,
 * inclusive em erro/exceção — ver SyncConnection::lockedConnection()/
 * finishRun()/failed(). Um retry do job (ShouldBeUnique não impede retry da
 * mesma instância, só dispatch concorrente) reaproveita a mesma linha, casada
 * pelo `job_uuid` (o mesmo em todo retry da mesma execução lógica — ver
 * SyncConnection::$jobUuid) — nunca só por estar `running`: uma run presa de
 * uma execução anterior (travou sem nunca chamar failed(), ex.: processo
 * morto) é fechada como erro em vez de reaproveitada (ver
 * SyncConnection::closeStuckRuns()).
 *
 * @property SyncTrigger $trigger
 * @property string|null $job_uuid
 * @property SyncRunStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $provider_updated_at
 * @property list<string>|null $warnings
 * @property array<string, mixed>|null $stats
 */
class BankSyncRun extends Model
{
    use BelongsToUser;

    /** @use HasFactory<BankSyncRunFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'connection_id', 'trigger', 'job_uuid', 'status', 'started_at', 'finished_at',
        'refresh_requested', 'provider_updated_at', 'added_count', 'updated_count',
        'bills_count', 'warnings', 'stats', 'error',
    ];

    /**
     * Espelha os defaults da migration: sem isso, um create() sem a chave
     * fica errado em memória até um refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'running',
        'refresh_requested' => false,
        'added_count' => 0,
        'updated_count' => 0,
        'bills_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => SyncTrigger::class,
            'status' => SyncRunStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'provider_updated_at' => 'immutable_datetime',
            'refresh_requested' => 'boolean',
            'added_count' => 'integer',
            'updated_count' => 'integer',
            'bills_count' => 'integer',
            'warnings' => 'array',
            'stats' => 'array',
        ];
    }

    protected static function newFactory(): BankSyncRunFactory
    {
        return BankSyncRunFactory::new();
    }

    /**
     * @return BelongsTo<BankConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    /**
     * @return HasMany<BankSyncRunItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BankSyncRunItem::class, 'run_id');
    }
}
