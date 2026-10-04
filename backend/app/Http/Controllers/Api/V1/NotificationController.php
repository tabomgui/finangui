<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\IndexNotificationsRequest;
use App\Http\Resources\NotificationCollection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class NotificationController extends Controller
{
    public function index(IndexNotificationsRequest $request): NotificationCollection
    {
        /** @var User $user */
        $user = $request->user();

        // reorder(): a relação notifications() já aplica latest() (só
        // created_at); um id (uuid) como critério de desempate mantém o
        // cursor estável quando duas notificações nascem no mesmo segundo
        // (ex.: vários alertas do mesmo SendAlerts).
        $notifications = $user->notifications()
            ->reorder()->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate($request->integer('per_page', 20))
            ->withQueryString();

        return new NotificationCollection($notifications, $user->unreadNotifications()->count());
    }

    public function read(Request $request, string $notification): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->notifications()->findOrFail($notification)->markAsRead();

        return response()->noContent();
    }

    public function readAll(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
