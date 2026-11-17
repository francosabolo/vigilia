<?php

namespace App\Notifications;

use App\Models\SafeArrival;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * F9 — to the guard: a resident is arriving (watch their gate), or did not confirm they got in.
 */
class SafeArrivalNotice extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public SafeArrival $arrival, public bool $overdue = false) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, ['push' => $notifiable->pushSubscriptions()->exists() ? WebPushChannel::class : null]);
    }

    public function inbox(object $notifiable): array
    {
        $resident = $this->arrival->resident;
        $who = $resident->name.($resident->lot ? " ({$resident->lot})" : '');

        return $this->overdue
            ? [
                'title' => "{$who} no confirmó que entró",
                'body' => 'Avisó que llegaba y pasó el tiempo sin confirmar. Mirá su portón y llamalo.'.($resident->phone ? " Celular: {$resident->phone}." : ''),
                'url' => route('dashboard', absolute: false),
                'level' => 'alarm',
                'kind' => 'arrival',
            ]
            : [
                'title' => "{$who} está llegando",
                'body' => 'Llega antes de las '.$this->arrival->expected_by->setTimezone($this->arrival->site->timezone ?? config('app.timezone'))->format('H:i').'. Seguilo por cámara hasta que entre.',
                'url' => route('dashboard', absolute: false),
                'level' => 'warning',
                'kind' => 'arrival',
            ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $inbox = $this->inbox($notifiable);

        return (new WebPushMessage)->title($inbox['title'])->body($inbox['body'])->icon('/icons/icon-192.png')
            ->tag('arrival-'.$this->arrival->id)->data(['url' => $inbox['url']]);
    }
}
