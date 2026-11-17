<?php

namespace App\Notifications;

use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * For the client's administration: an incident opened in one of its properties. Informative (the guard and the
 * central handle it), so no WhatsApp; inbox, live and push.
 */
class IncidentNotice extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Incident $incident) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, ['push' => $notifiable->pushSubscriptions()->exists() ? WebPushChannel::class : null]);
    }

    public function inbox(object $notifiable): array
    {
        $incident = $this->incident->loadMissing('site');

        return [
            'title' => "{$incident->site->name}: {$incident->title}",
            'body' => 'La guardia ya fue avisada. Podés seguirlo desde el incidente.',
            'url' => route('incidents.show', $incident, absolute: false),
            'level' => in_array($incident->severity->value, ['high', 'critical'], true) ? 'alarm' : 'warning',
            'kind' => 'incident',
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $inbox = $this->inbox($notifiable);

        return (new WebPushMessage)
            ->title($inbox['title'])
            ->body($inbox['body'])
            ->icon('/icons/icon-192.png')
            ->tag('incident-'.$this->incident->id)
            ->data(['url' => $inbox['url']]);
    }
}
