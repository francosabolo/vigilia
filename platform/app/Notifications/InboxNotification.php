<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Every notification of Vigilia lands in the person's in-app inbox (the bell) and arrives live on screen.
 * E-mail, WhatsApp and push go on top of that, according to each person's preferences.
 */
abstract class InboxNotification extends Notification
{
    /**
     * @return array{title: string, body: string, url: string|null, level: 'info'|'warning'|'alarm', kind: string}
     */
    abstract public function inbox(object $notifiable): array;

    /**
     * In-app inbox + live, plus the external channels this notification wants and the person accepts.
     *
     * @param  array<string, string|null>  $external  preference key ('email', 'whatsapp', 'push') => channel
     * @return array<int, string>
     */
    protected function channels(object $notifiable, array $external): array
    {
        if (! $notifiable instanceof User) {
            return array_values(array_filter($external));
        }

        $channels = ['database', 'broadcast'];
        foreach ($external as $preference => $channel) {
            if ($channel !== null && $notifiable->wantsNotificationsBy($preference)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->inbox($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->inbox($notifiable));
    }
}
