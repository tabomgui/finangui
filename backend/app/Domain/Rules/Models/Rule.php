<?php

namespace App\Domain\Rules\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\RuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property 'all'|'any' $match
 * @property list<array{field: 'description'|'original_description'|'payee'|'notes'|'amount'|'direction'|'account_id'|'date', op: 'contains'|'not_contains'|'starts_with'|'ends_with'|'equals'|'not_equals'|'regex'|'gt'|'gte'|'lt'|'lte', value: string|int}|array{match: 'all'|'any', conditions: list<array{field: 'description'|'original_description'|'payee'|'notes'|'amount'|'direction'|'account_id'|'date', op: 'contains'|'not_contains'|'starts_with'|'ends_with'|'equals'|'not_equals'|'regex'|'gt'|'gte'|'lt'|'lte', value: string|int}>}> $conditions
 * @property list<array{type: 'set_category'|'set_description'|'set_payee'|'add_tag'|'ignore', category_id?: int, tag_id?: int, value?: string}> $actions
 */
class Rule extends Model
{
    use BelongsToUser;

    /** @use HasFactory<RuleFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'priority', 'is_active', 'match', 'conditions', 'actions',
        'last_applied_at', 'last_applied_changes',
    ];

    /**
     * Espelham os defaults da migration: sem isso, um create() sem a chave
     * fica null em memória até um refresh, mesmo a coluna sendo default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 0,
        'is_active' => true,
        'match' => 'all',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'conditions' => 'array',
            'actions' => 'array',
            'last_applied_at' => 'immutable_datetime',
            'last_applied_changes' => 'integer',
        ];
    }

    protected static function newFactory(): RuleFactory
    {
        return RuleFactory::new();
    }

    /**
     * @param  Builder<Rule>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('priority')->orderBy('id');
    }
}
