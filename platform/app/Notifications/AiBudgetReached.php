<?php

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class AiBudgetReached extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Site $site, public int $cap) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, ['email' => 'mail']);
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

    public function inbox(object $notifiable): array
    {
        return [
            'title' => "Tope diario de IA alcanzado en {$this->site->name}",
            'body' => 'Hasta mañana las detecciones se alertan sin el filtro de IA. Revisá qué cámara genera tantas.',
            'url' => route('sites.show', ['site' => $this->site, 'tab' => 'ajustes'], absolute: false),
            'level' => 'warning',
            'kind' => 'ai_budget',
        ];
    }
}
