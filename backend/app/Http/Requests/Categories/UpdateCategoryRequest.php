<?php

namespace App\Http\Requests\Categories;

use App\Domain\Categories\Models\Category;
use App\Http\Requests\ApiRequest;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateCategoryRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->category();
        $parentId = $this->has('parent_id') ? $this->input('parent_id') : $category->parent_id;

        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:60',
                Rule::unique('categories', 'name')
                    ->ignore($category->id)
                    ->where('user_id', $this->userId())
                    ->where(fn (Builder $q) => $parentId !== null
                        ? $q->where('parent_id', (int) $parentId)
                        : $q->whereNull('parent_id')),
            ],
            'parent_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('categories', 'id')->where('user_id', $this->userId())->whereNull('parent_id'),
            ],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_transfer' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('parent_id')) {
                return;
            }

            $category = $this->category();
            $newParentId = $this->input('parent_id');
            $name = $this->input('name', $category->name);

            $duplicate = Category::query()
                ->where('user_id', $this->userId())
                ->where('name', $name)
                ->where('id', '!=', $category->id)
                ->when(
                    $newParentId !== null,
                    fn ($q) => $q->where('parent_id', (int) $newParentId),
                    fn ($q) => $q->whereNull('parent_id'),
                )
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('parent_id', 'Já existe uma categoria com esse nome nesse nível.');

                return;
            }

            if ($newParentId === null) {
                return;
            }

            $parent = Category::query()->find((int) $newParentId);
            if ($parent === null) {
                return;
            }

            if ($parent->is($category)) {
                $validator->errors()->add('parent_id', 'Uma categoria não pode ser pai de si mesma.');

                return;
            }

            if ($category->children()->exists()) {
                $validator->errors()->add('parent_id', 'Categorias com subcategorias não podem virar subcategoria.');
            }

            if ($parent->kind !== $category->kind) {
                $validator->errors()->add('parent_id', 'A subcategoria deve ter o mesmo tipo da categoria pai.');
            }
        }];
    }

    private function category(): Category
    {
        $category = $this->route('category');
        assert($category instanceof Category);

        return $category;
    }
}
