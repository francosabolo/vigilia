<?php

namespace App\Notifications;

use App\Models\Zone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class ZoneAutonomyDemoted extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Zone $zone, public int $mistakes) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, ['email' => 'mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $site = $this->zone->camera->site;

        return (new MailMessage)
            ->subject("La zona {$this->zone->name} perdió la disuasión automática")
            ->line("En {$site->name}, la disuasión automática de la zona {$this->zone->name} se activó {$this->mistakes} veces por falsas alarmas esta semana.")
            ->line('La zona volvió a Asistida: alerta a la guardia, pero ya no activa el altavoz sola. Se puede volver a activar cuando la zona junte alertas confiables de nuevo.')
            ->action('Ver el lugar', route('sites.show', ['site' => $site, 'tab' => 'camaras']));
    }

    public function inbox(object $notifiable): array
    {
        $site = $this->zone->camera->site;

        return [
            'title' => "La zona {$this->zone->name} volvió a Asistida",
            'body' => "{$site->name}: la disuasión automática se activó {$this->mistakes} veces por falsas alarmas esta semana.",
            'url' => route('sites.show', ['site' => $site, 'tab' => 'camaras'], absolute: false),
            'level' => 'warning',
            'kind' => 'autonomy',
        ];
    }
}
