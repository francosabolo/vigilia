<?php

namespace App\Notifications;

use App\Models\Incident;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class IncidentAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Incident $incident, public bool $escalated = false) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return [WhatsAppChannel::class];
        }

        return array_values(array_filter([
            $notifiable->phone ? WhatsAppChannel::class : null,
            $notifiable->pushSubscriptions()->exists() ? WebPushChannel::class : null,
        ]));
    }

    /**
     * Template parameters: header image, body {{1}} title, {{2}} site, {{3}} description,
     * quick-reply buttons 0 = "Voy / Recibido" (ack) and 1 = "Falsa alarma".
     *
     * @return array<string, mixed>
     */
    public function toWhatsApp(object $notifiable): array
    {
        $incident = $this->incident->loadMissing('site');
        $header = $incident->snapshot_path
            ? [['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $incident->mediaUrl('snapshot')]]]]]
            : [];

        return [
            'name' => config('seguria.whatsapp.template'),
            'language' => ['code' => config('seguria.whatsapp.template_language')],
            'components' => [
                ...$header,
                ['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => ($this->escalated ? '[ESCALADO] ' : '').$incident->title],
                    ['type' => 'text', 'text' => $incident->site->name],
                    ['type' => 'text', 'text' => $incident->description ?: 'Sin descripción'],
                ]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0', 'parameters' => [['type' => 'payload', 'payload' => "ack:{$incident->id}"]]],
                ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => '1', 'parameters' => [['type' => 'payload', 'payload' => "false:{$incident->id}"]]],
            ],
        ];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $incident = $this->incident->loadMissing('site');

        return (new WebPushMessage)
            ->title(($this->escalated ? '⚠️ ESCALADO · ' : '🚨 ').$incident->title)
            ->body($incident->site->name.' — '.($incident->description ?: 'Nueva alerta'))
            ->icon('/icons/icon-192.png')
            ->image($incident->snapshot_path ? $incident->mediaUrl('snapshot') : '')
            ->tag('incident-'.$incident->id)
            ->renotify()
            ->requireInteraction()
            ->vibrate([300, 100, 300, 100, 300])
            ->data(['url' => route('incidents.show', $incident)])
            ->action('Abrir', 'open');
    }
}
