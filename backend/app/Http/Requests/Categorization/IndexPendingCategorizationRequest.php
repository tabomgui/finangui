<?php

namespace App\Http\Requests\Categorization;

use App\Domain\Transactions\Enums\Direction;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class IndexPendingCategorizationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'direction' => ['sometimes', Rule::enum(Direction::class)],
            'search' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function direction(): ?Direction
    {
        return $this->filled('direction') ? Direction::from($this->string('direction')->value()) : null;
    }

    public function search(): ?string
    {
        return $this->filled('search') ? $this->string('search')->value() : null;
    }
}
