<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferencesController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/notifications', [
            'preferences' => collect(User::NOTIFICATION_CHANNELS)->mapWithKeys(fn (string $channel) => [$channel => $user->wantsNotificationsBy($channel)]),
            'hasPhone' => (bool) $user->phone,
            'pushDevices' => $user->pushSubscriptions()->count(),
            'vapidPublicKey' => config('webpush.vapid.public_key'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'boolean'],
            'whatsapp' => ['required', 'boolean'],
            'push' => ['required', 'boolean'],
        ]);

        $request->user()->update(['notification_prefs' => $data]);

        return back();
    }
}
