<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Acrescenta `meta.unread_count` à paginação por cursor padrão — ver
 * App\Http\Controllers\Api\V1\NotificationController::index().
 */
final class NotificationCollection extends ResourceCollection
{
    public $collects = NotificationResource::class;

    public function __construct($resource, private readonly int $unreadCount)
    {
        parent::__construct($resource);
    }

    /**
     * @return array{meta: array{unread_count: int}}
     */
    public function with(Request $request): array
    {
        return ['meta' => ['unread_count' => $this->unreadCount]];
    }
}
