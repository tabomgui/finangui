<?php

namespace App\Domain\Rules\Enums;

use App\Domain\Rules\Support\CategoryChangePlanner;

/**
 * Quem deu a categoria atual de um lançamento, lido de categorized_by
 * ("manual", "history", "pluggy" ou "rule:{id}").
 */
enum CategorySource: string
{
    case Manual = 'manual';
    case History = 'history';
    case Pluggy = 'pluggy';
    case Rule = 'rule';

    /**
     * Nulo quando não há origem conhecida (valor vazio ou fora do formato).
     */
    public static function fromCategorizedBy(?string $categorizedBy): ?self
    {
        if ($categorizedBy === null) {
            return null;
        }

        if (CategoryChangePlanner::ruleIdOf($categorizedBy) !== null) {
            return self::Rule;
        }

        return match ($categorizedBy) {
            'manual' => self::Manual,
            'history' => self::History,
            'pluggy' => self::Pluggy,
            default => null,
        };
    }
}
