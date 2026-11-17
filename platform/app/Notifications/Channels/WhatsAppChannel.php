<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends notifications through the WhatsApp Cloud API (Meta Graph API).
 * Notifications implement toWhatsApp() returning the "template" part of the message.
 */
class WhatsAppChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $to = $notifiable->routeNotificationFor('whatsapp', $notification);

        if (! $to || ! config('vigilia.whatsapp.enabled') || ! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $config = config('vigilia.whatsapp');

        $response = Http::withToken($config['access_token'])
            ->timeout(15)
            ->post("https://graph.facebook.com/{$config['graph_version']}/{$config['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => ltrim($to, '+'),
                'type' => 'template',
                'template' => $notification->toWhatsApp($notifiable),
            ]);

        if ($response->failed()) {
            Log::error('WhatsApp message failed', ['to' => $to, 'status' => $response->status(), 'body' => $response->json()]);
            $response->throw();
        }
    }
}
