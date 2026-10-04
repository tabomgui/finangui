<?php

namespace App\Http\Resources;

use App\Domain\Goals\Models\Goal;
use App\Domain\Goals\Support\GoalProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Espera o Goal carregado com account (quando houver) via
 * withBalance() — ver GoalController::baseQuery() — para progress/account
 * não disparar uma consulta extra por item.
 *
 * @mixin Goal
 */
final class GoalResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     target_amount: int,
     *     target_date: string|null,
     *     account_id: int|null,
     *     account?: array{id: int|null, name: string|null},
     *     color: string|null,
     *     icon: string|null,
     *     achieved_at: string|null,
     *     progress: int,
     *     remaining: int,
     *     percent: int,
     *     monthly_needed?: int,
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Goal $goal */
        $goal = $this->resource;
        $progress = GoalProgress::for($goal);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'target_amount' => $this->target_amount->cents,
            'target_date' => $this->target_date?->toDateString(),
            'account_id' => $this->account_id,
            // Omitido sem conta vinculada (não `null`): ver CLAUDE.md sobre
            // Readable<T> do openapi-fetch.
            'account' => $this->when($this->account_id !== null, fn () => [
                'id' => $goal->account?->id,
                'name' => $goal->account?->name,
            ]),
            'color' => $this->color,
            'icon' => $this->icon,
            'achieved_at' => $this->achieved_at?->toIso8601String(),
            'progress' => $progress['progress'],
            'remaining' => $progress['remaining'],
            'percent' => $progress['percent'],
            'monthly_needed' => $this->when(array_key_exists('monthly_needed', $progress), fn () => $progress['monthly_needed']),
        ];
    }
}
