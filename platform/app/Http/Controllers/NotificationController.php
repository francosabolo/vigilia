<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app inbox behind the bell: every notification the person received, newest first.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('notifications/index', [
            'notifications' => $request->user()->notifications()->paginate(30)->through(fn (DatabaseNotification $notification) => $this->present($notification)),
        ]);
    }

    /**
     * The bell's dropdown.
     */
    public function latest(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()->limit(8)->get()->map(fn (DatabaseNotification $notification) => $this->present($notification)),
        ]);
    }

    /**
     * Opening a notification marks it read and takes the person to what it is about.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return redirect($item->data['url'] ?? route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'title' => $notification->data['title'] ?? 'Notificación',
            'body' => $notification->data['body'] ?? '',
            'level' => $notification->data['level'] ?? 'info',
            'kind' => $notification->data['kind'] ?? 'other',
            'read' => $notification->read_at !== null,
            'at' => $notification->created_at->toIso8601String(),
        ];
    }
}
