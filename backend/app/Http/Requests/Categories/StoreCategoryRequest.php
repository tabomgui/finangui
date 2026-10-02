<?php

namespace App\Http\Requests\Categories;

use App\Domain\Categories\Enums\CategoryKind;
use App\Domain\Categories\Models\Category;
use App\Http\Requests\ApiRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreCategoryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('categories', 'name')
                    ->where('user_id', $this->userId())
                    ->where(fn (Builder $q) => $this->filled('parent_id')
                        ? $q->where('parent_id', $this->integer('parent_id'))
                        : $q->whereNull('parent_id')),
            ],
            'kind' => ['required', Rule::enum(CategoryKind::class)],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('categories', 'id')->where('user_id', $this->userId())->whereNull('parent_id'),
            ],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_transfer' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('parent_id')) {
                return;
            }

            $parent = Category::query()->find($this->integer('parent_id'));
            if ($parent !== null && $parent->kind->value !== $this->input('kind')) {
                $validator->errors()->add('parent_id', 'A subcategoria deve ter o mesmo tipo da categoria pai.');
            }
        }];
    }
}
