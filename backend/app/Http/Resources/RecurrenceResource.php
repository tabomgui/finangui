<?php

namespace App\Http\Resources;

use App\Domain\Recurrences\Models\Recurrence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Recurrence
 */
final class RecurrenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ]),
            'category_id' => $this->category_id,
            'category' => $this->when($this->relationLoaded('category') && $this->category !== null, fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'icon' => $this->category->icon,
                'color' => $this->category->color,
            ]),
            'description' => $this->description,
            'amount' => $this->amount->cents,
            'direction' => $this->direction,
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'day_of_month' => $this->day_of_month,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'generated_until' => $this->generated_until?->toDateString(),
            'match_pattern' => $this->match_pattern,
            'is_active' => $this->is_active,
            // Só existe quando a recorrência veio de App\Domain\Recurrences\Queries\RecurrenceList
            // (withMin da próxima ocorrência prevista); chave omitida em vez de null — ver
            // CLAUDE.md sobre Readable<T> do openapi-fetch.
            'next_date' => $this->when(
                $this->next_date !== null,
                fn () => substr((string) $this->next_date, 0, 10),
            ),
        ];
    }
}
