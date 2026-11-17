<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * M22 — a house without a guard: the owner is told at once (and the central, by the usual escalation).
 * Like every resident notice, it carries no images.
 */
class OwnerAlert extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Incident $incident) {}

    /**
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['database' => 'alerts', 'broadcast' => 'alerts', WebPushChannel::class => 'alerts'];
    }

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
            'title' => "Alerta en {$this->incident->site->name}",
            'body' => "{$this->incident->title}. La central ya fue avisada. Si estás cerca, no te acerques: esperá a la seguridad.",
            'url' => route('residents.home', absolute: false),
            'level' => 'alarm',
            'kind' => 'owner_alert',
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $inbox = $this->inbox($notifiable);

        return (new WebPushMessage)->title($inbox['title'])->body($inbox['body'])->icon('/icons/icon-192.png')
            ->tag('owner-'.$this->incident->id)->requireInteraction()->renotify()->data(['url' => $inbox['url']]);
    }
}
