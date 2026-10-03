<?php

namespace App\Domain\Categories\Models;

use App\Domain\Categories\Enums\CategoryKind;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToUser;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'parent_id', 'name', 'kind', 'icon', 'color', 'is_transfer', 'is_archived'];

    /**
     * Espelham os defaults da migration: sem isso, um create() sem a chave
     * fica null em memória até um refresh, mesmo a coluna sendo default false.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_transfer' => false,
        'is_archived' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CategoryKind::class,
            'is_transfer' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Category, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Categoria que uma regra/histórico ainda pode atribuir: não arquivada.
     * Compartilhado por CategorizeTransaction e ApplyRuleOutcome — as duas
     * conferem se a categoria apontada por uma regra ainda é usável antes
     * de gravá-la numa transação.
     *
     * @param  Builder<Category>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_archived', false);
    }
}
