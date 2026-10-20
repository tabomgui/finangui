<?php

namespace App\Domain\Rules\Errors;

use App\Domain\Rules\Support\CategoryChangePlanner;
use App\Domain\Shared\DomainError;

final class TooManyCategoryChanges extends DomainError
{
    public function __construct(int $count = 0)
    {
        $limit = CategoryChangePlanner::MAX_CHANGES;

        parent::__construct($count > 0
            ? "São {$count} lançamentos para alterar; o limite por vez é {$limit}. Desmarque algum grupo e aplique em partes."
            : "O limite por vez é {$limit} lançamentos. Desmarque algum grupo e aplique em partes.");
    }

    public function errorCode(): string
    {
        return 'too_many_category_changes';
    }
}
