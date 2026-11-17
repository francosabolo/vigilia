<?php

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class GuardSupervisionAlert extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Site $site, public string $title, public string $body) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels($notifiable, ['email' => 'mail']);
    }

    public function inbox(object $notifiable): array
    {
        return ['title' => $this->title, 'body' => $this->body, 'url' => route('guards.index', absolute: false), 'level' => 'warning', 'kind' => 'guards'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->line($this->body)->action('Ver guardias', route('guards.index'));
    }
}
