<?php

namespace App\Http\Resources;

use App\Domain\Notifications\Enums\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Espera `data` no formato gravado pelas notificações de
 * App\Domain\Notifications\Notifications (`{type, key, title, body, url}`).
 *
 * @mixin DatabaseNotification
 */
final class NotificationResource extends JsonResource
{
    /**
     * Os casts (string)/NotificationType::from() são de propósito: `data` é
     * um array cast genérico (sem shape conhecida) — sem eles, o Scramble
     * não consegue atribuir um tipo à chave lida de volta dele (ver mesmo
     * raciocínio em App\Http\Controllers\Api\V1\TransferSuggestionController::detect()).
     *
     * @return array{id: string, type: NotificationType, title: string, body: string, url: string, read_at: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, string> $data */
        $data = $this->data;

        return [
            'id' => $this->id,
            'type' => NotificationType::fromValue($data['type']),
            'title' => (string) $data['title'],
            'body' => (string) $data['body'],
            'url' => (string) $data['url'],
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
