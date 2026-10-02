<?php

namespace App\Http\Requests\Tags;

use App\Domain\Tags\Models\Tag;
use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

final class SaveTagRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Tag|null $tag */
        $tag = $this->route('tag');

        return [
            'name' => [
                $tag ? 'sometimes' : 'required', 'string', 'max:40',
                Rule::unique('tags', 'name')->where('user_id', $this->userId())->ignore($tag?->id),
            ],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
