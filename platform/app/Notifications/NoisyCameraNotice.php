<?php

namespace App\Notifications;

use App\Models\Camera;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class NoisyCameraNotice extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Camera $camera) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, []);
    }

    public function inbox(object $notifiable): array
    {
        return [
            'title' => "Cámara ruidosa: {$this->camera->name} ({$this->camera->site->name})",
            'body' => 'Detecta cientos de movimientos por hora y casi todos son descartados. Suele ser lluvia, una telaraña o insectos frente al infrarrojo, faros de autos o ramas. Revisala, limpiala o tapá con una zona de privacidad lo que no hace falta mirar.',
            'url' => route('sites.show', ['site' => $this->camera->site_id, 'tab' => 'camaras'], absolute: false),
            'level' => 'warning',
            'kind' => 'camera_noisy',
        ];
    }
}
