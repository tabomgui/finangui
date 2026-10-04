<?php

namespace App\Domain\Notifications\Enums;

/**
 * Discrimina as notificações em `data.type` — ver
 * App\Domain\Notifications\Notifications e
 * App\Http\Resources\NotificationResource.
 */
enum NotificationType: string
{
    case StatementDue = 'statement_due';
    case BudgetExceeded = 'budget_exceeded';
    case OccurrenceOverdue = 'occurrence_overdue';
    case NeedsReauth = 'needs_reauth';

    /**
     * Mesmo que from() (nativo do enum), com um corpo de verdade: o
     * Scramble não enxerga o tipo de retorno de from()/tryFrom() (métodos
     * sintéticos, sem AST), então App\Http\Resources\NotificationResource
     * usa este método para `type` sair documentado como o enum, não
     * como string solta.
     */
    public static function fromValue(string $value): self
    {
        return self::from($value);
    }
}
