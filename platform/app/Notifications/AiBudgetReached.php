<?php

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AiBudgetReached extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Site $site, public int $cap) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tope diario de IA alcanzado en {$this->site->name}")
            ->line("{$this->site->name} llegó a {$this->cap} verificaciones de IA hoy.")
            ->line('Hasta mañana, las detecciones se alertan sin el filtro de IA: la guardia va a recibir más avisos.')
            ->line('Suele pasar por una cámara con lluvia, una telaraña o una luz que parpadea. Revisá qué cámara está generando las detecciones.')
            ->action('Ver el lugar', route('sites.show', $this->site));
    }
}
