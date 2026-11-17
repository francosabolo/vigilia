<?php

namespace App\Notifications;

use App\Models\AccessEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * D7 — a visit or a provider still inside after their time.
 */
class VisitorOverdue extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AccessEntry $entry) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, []);
    }

    public function inbox(object $notifiable): array
    {
        $entry = $this->entry;
        $who = ($entry->unit ? "para la {$entry->unit}" : 'sin unidad').($entry->visiting ? " ({$entry->visiting})" : '');

        return [
            'title' => "{$entry->name} sigue adentro",
            'body' => "Entró a las {$entry->created_at->setTimezone($entry->site->timezone ?? config('app.timezone'))->format('H:i')} {$who} y su visita ya terminó. Confirmá con el vecino o registrá la salida.",
            'url' => route('access.index', absolute: false),
            'level' => 'warning',
            'kind' => 'visitor_overdue',
        ];
    }
}
