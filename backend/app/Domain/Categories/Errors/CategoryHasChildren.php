<?php

namespace App\Domain\Categories\Errors;

use App\Domain\Shared\DomainError;

final class CategoryHasChildren extends DomainError
{
    public function __construct()
    {
        parent::__construct('Exclua ou mova as subcategorias antes de excluir esta categoria.');
    }

    public function errorCode(): string
    {
        return 'category_has_children';
    }
}
