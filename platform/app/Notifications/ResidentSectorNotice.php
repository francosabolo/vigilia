<?php

namespace App\Notifications;

use App\Enums\IncidentResolution;
use App\Models\Incident;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Privacy-first notice for residents: says that something happened in their sector and that it is being handled.
 * Never includes images, AI descriptions or anything that identifies a person.
 */
class ResidentSectorNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Incident $incident, public bool $closed = false) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->pushSubscriptions()->exists() ? [WebPushChannel::class] : [];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $sector = $this->incident->sector;

        [$title, $body] = $this->closed
            ? [
                "Sector {$sector}: alerta cerrada",
                $this->incident->resolution === IncidentResolution::FalseAlarm ? 'Era una falsa alarma. Todo en orden.' : 'La guardia intervino y la situación está controlada.',
            ]
            : ["Alerta en el sector {$sector}", 'La guardia ya fue avisada y está revisando.'];

        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon('/icons/icon-192.png')
            ->tag('sector-incident-'.$this->incident->id)
            ->data(['url' => route('residents.home')]);
    }
}
