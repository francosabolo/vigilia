<?php

namespace App\Notifications;

use App\Models\ResidentMessage;
use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A message from the guard or the administration to one or several residents. On the phone when they have the
 * app, by e-mail when they do not (or always, when it is urgent).
 */
class StaffMessageToResident extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ResidentMessage $message, public Site $site) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $push = $notifiable->pushSubscriptions()->exists();

        return $this->channels($notifiable, [
            'push' => $push ? WebPushChannel::class : null,
            'email' => ! $push || $this->message->urgent ? 'mail' : null,
        ]);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title(($this->message->urgent ? 'Urgente: ' : '')."Aviso de la guardia de {$this->site->name}")
            ->body($this->message->body)
            ->icon('/icons/icon-192.png')
            ->tag('staff-message-'.$this->message->id)
            ->data(['url' => route('residents.home')]);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->message->urgent ? 'Urgente: ' : '')."Aviso de la guardia de {$this->site->name}")
            ->greeting("Hola, {$notifiable->name}")
            ->line($this->message->body)
            ->line('Si necesitás algo, comunicate con la guardia.');
    }

    public function inbox(object $notifiable): array
    {
        return [
            'title' => ($this->message->urgent ? 'Urgente: ' : '')."Aviso de la guardia de {$this->site->name}",
            'body' => $this->message->body,
            'url' => route('residents.home', absolute: false),
            'level' => $this->message->urgent ? 'alarm' : 'info',
            'kind' => 'message',
        ];
    }
}
