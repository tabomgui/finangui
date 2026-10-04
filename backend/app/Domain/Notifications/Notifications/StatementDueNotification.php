<?php

namespace App\Domain\Notifications\Notifications;

use App\Domain\Notifications\Support\DedupableNotification;
use Illuminate\Notifications\Notification;

/**
 * Fatura de cartão não paga, vencendo em até 3 dias (inclui hoje) — ver
 * App\Domain\Notifications\Jobs\SendAlerts.
 */
final class StatementDueNotification extends Notification implements DedupableNotification
{
    public function __construct(
        private readonly int $statementId,
        private readonly int $accountId,
        private readonly string $cardName,
        private readonly int $daysUntilDue,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function dedupeKey(): string
    {
        return "statement_due:{$this->statementId}";
    }

    /**
     * @return array{type: string, key: string, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'statement_due',
            'key' => $this->dedupeKey(),
            'title' => 'Fatura vencendo',
            'body' => "Fatura do {$this->cardName} vence {$this->dueWording()}.",
            'url' => "/cartoes/{$this->accountId}?fatura={$this->statementId}",
        ];
    }

    private function dueWording(): string
    {
        return match ($this->daysUntilDue) {
            0 => 'hoje',
            1 => 'amanhã',
            default => "em {$this->daysUntilDue} dias",
        };
    }
}
