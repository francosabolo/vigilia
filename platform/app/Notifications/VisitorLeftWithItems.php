<?php

namespace App\Notifications;

use App\Models\AccessEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * D8 — the visitor left carrying things they did not bring: the resident who received them is told, calmly.
 */
class VisitorLeftWithItems extends InboxNotification implements ShouldQueue
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
        $items = $this->entry->exit_check['description'] ?? 'algo que no tenía al entrar';

        return [
            'title' => "{$this->entry->name} salió con {$items}",
            'body' => 'Al entrar no lo llevaba. Si no es algo que le diste, avisale a la guardia.',
            'url' => route('residents.home', absolute: false),
            'level' => 'warning',
            'kind' => 'visitor_left_with_items',
        ];
    }
}
