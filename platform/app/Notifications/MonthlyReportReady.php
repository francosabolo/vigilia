<?php

namespace App\Notifications;

use App\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

class MonthlyReportReady extends InboxNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Site $site, public string $month, public string $monthLabel, public string $summary) {}

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
            ->subject("Informe de seguridad de {$this->monthLabel}: {$this->site->name}")
            ->greeting("Hola, {$notifiable->name}")
            ->line("Ya está el informe del servicio de seguridad de {$this->site->name} de {$this->monthLabel}.")
            ->line(strtok($this->summary, "\n"))
            ->action('Ver el informe completo', route('reports.site', ['site' => $this->site, 'month' => $this->month]))
            ->line('Lo podés guardar como PDF desde el navegador.');
    }

    public function inbox(object $notifiable): array
    {
        return [
            'title' => "Informe de {$this->monthLabel}: {$this->site->name}",
            'body' => (string) strtok($this->summary, "\n"),
            'url' => route('reports.site', ['site' => $this->site, 'month' => $this->month], absolute: false),
            'level' => 'info',
            'kind' => 'report',
        ];
    }
}
