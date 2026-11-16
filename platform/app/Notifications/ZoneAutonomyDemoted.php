<?php

namespace App\Notifications;

use App\Models\Zone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ZoneAutonomyDemoted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Zone $zone, public int $mistakes) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
}
