<?php

namespace App\Domain\Categorization\Support;

use App\Domain\Categories\Enums\CategoryKind;
use App\Domain\Categories\Models\Category;
use App\Domain\Transactions\Enums\Direction;

/**
 * Se uma categoria pode ser aplicada a lançamentos de uma direção: categoria
 * de transferência (inclusive filha de uma, ver
 * Category::isTransferEffective()) serve para entrada e saída; as demais só
 * quando o tipo bate com a direção (entrada → receita, saída → despesa).
 * Usada na validação do apply e para descartar a sugestão pelo histórico que
 * o apply recusaria.
 */
final class CategoryDirection
{
    public static function accepts(Category $category, Direction $direction): bool
    {
        if ($category->isTransferEffective()) {
            return true;
        }

        $expectedKind = $direction === Direction::In ? CategoryKind::Income : CategoryKind::Expense;

        return $category->kind === $expectedKind;
    }
}
