<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * F6 — how the week went in the resident's property, in one sentence, without images or anyone's data.
 */
class ResidentWeeklySummary extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $siteName, public string $summary) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, ['push' => $notifiable->pushSubscriptions()->exists() ? WebPushChannel::class : null]);
    }

    public function inbox(object $notifiable): array
    {
        return [
            'title' => "Tu semana en {$this->siteName}",
            'body' => $this->summary,
            'url' => route('residents.home', absolute: false),
            'level' => 'info',
            'kind' => 'weekly',
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)->title("Tu semana en {$this->siteName}")->body($this->summary)->icon('/icons/icon-192.png')->data(['url' => route('residents.home')]);
    }
}
