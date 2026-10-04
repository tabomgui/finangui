<?php

namespace App\Domain\Notifications\Notifications;

use App\Domain\Notifications\Support\DedupableNotification;
use Illuminate\Notifications\Notification;

/**
 * Categoria do mês atual cujo gasto passou do orçamento — ver
 * App\Domain\Budgets\Queries\MonthBudget e App\Domain\Notifications\Jobs\SendAlerts.
 */
final class BudgetExceededNotification extends Notification implements DedupableNotification
{
    public function __construct(
        private readonly int $categoryId,
        private readonly string $categoryName,
        private readonly string $yearMonth,
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
        return "budget_exceeded:{$this->categoryId}:{$this->yearMonth}";
    }

    /**
     * @return array{type: string, key: string, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'budget_exceeded',
            'key' => $this->dedupeKey(),
            'title' => 'Orçamento estourado',
            'body' => "Orçamento de {$this->categoryName} estourado.",
            'url' => '/orcamento',
        ];
    }
}
