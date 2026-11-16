<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\IncidentResolution;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\User;
use App\Services\Incidents\IncidentWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives guard replies (quick-reply buttons) from the WhatsApp Cloud API.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        abort_unless(
            $request->query('hub_mode') === 'subscribe'
            && hash_equals((string) config('seguria.whatsapp.verify_token'), (string) $request->query('hub_verify_token')),
            403,
        );

        return response((string) $request->query('hub_challenge'));
    }

    public function handle(Request $request, IncidentWorkflow $workflow): JsonResponse
    {
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) config('seguria.whatsapp.app_secret'));
        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256')), 403);

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    $payload = $message['button']['payload'] ?? $message['interactive']['button_reply']['id'] ?? null;

                    if ($payload) {
                        $this->apply($payload, (string) ($message['from'] ?? ''), $workflow);
                    }
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function apply(string $payload, string $from, IncidentWorkflow $workflow): void
    {
        if (! preg_match('/^(ack|false|confirm):(\d+)$/', $payload, $matches)) {
            return;
        }

        $incident = Incident::find((int) $matches[2]);

        if (! $incident) {
            return;
        }

        $user = $from !== '' ? User::where('phone', '+'.ltrim($from, '+'))->orWhere('phone', $from)->first() : null;

        match ($matches[1]) {
            'ack' => $workflow->acknowledge($incident, $user, 'whatsapp'),
            'false' => $workflow->resolve($incident, IncidentResolution::FalseAlarm, $user, 'whatsapp'),
            'confirm' => $workflow->resolve($incident, IncidentResolution::Confirmed, $user, 'whatsapp'),
        };
    }
}
