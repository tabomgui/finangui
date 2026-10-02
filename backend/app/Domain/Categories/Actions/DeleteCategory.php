<?php

namespace App\Domain\Categories\Actions;

use App\Domain\Categories\Errors\CategoryHasChildren;
use App\Domain\Categories\Models\Category;

final class DeleteCategory
{
    public function handle(Category $category): void
    {
        if ($category->children()->exists()) {
            throw new CategoryHasChildren;
        }

        $category->delete();
    }
}
