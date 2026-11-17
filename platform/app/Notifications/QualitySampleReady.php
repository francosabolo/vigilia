<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class QualitySampleReady extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $count) {}

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
            'title' => 'Control de calidad de la IA de esta semana',
            'body' => "{$this->count} detecciones que la IA descartó, elegidas al azar. Revisarlas lleva unos minutos.",
            'url' => route('quality.index', absolute: false),
            'level' => 'info',
            'kind' => 'quality',
        ];
    }
}
