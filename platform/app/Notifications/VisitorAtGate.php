<?php

namespace App\Notifications;

use App\Models\AccessEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * To the resident: your visit arrived, or someone without notice is at the gate asking for you.
 */
class VisitorAtGate extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AccessEntry $entry) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, ['push' => $notifiable->pushSubscriptions()->exists() ? WebPushChannel::class : null]);
    }

    public function inbox(object $notifiable): array
    {
        $waiting = $this->entry->status === 'waiting';

        return [
            'title' => $waiting ? "{$this->entry->name} está en la guardia" : "Llegó {$this->entry->name}",
            'body' => $waiting
                ? 'Pregunta por vos y no tiene autorización. Entrá para dejarlo pasar o no.'
                : 'La guardia lo dejó pasar con tu autorización'.($this->entry->plate ? " (patente {$this->entry->plate})." : '.'),
            'url' => route('residents.home', absolute: false).'#visitas',
            'level' => $waiting ? 'alarm' : 'info',
            'kind' => 'access',
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $inbox = $this->inbox($notifiable);

        return (new WebPushMessage)->title($inbox['title'])->body($inbox['body'])->icon('/icons/icon-192.png')
            ->tag('access-'.$this->entry->id)->requireInteraction()->data(['url' => $inbox['url']]);
    }
}
