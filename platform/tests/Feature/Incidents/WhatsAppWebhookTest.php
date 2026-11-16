<?php

use App\Enums\IncidentResolution;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\User;

beforeEach(function () {
    config(['seguria.whatsapp.app_secret' => 'secret', 'seguria.whatsapp.verify_token' => 'verify-me']);
});

function whatsappReply(string $payload, string $from = '5491155550000'): array
{
    return ['entry' => [['changes' => [['value' => ['messages' => [[
        'from' => $from, 'type' => 'button', 'button' => ['payload' => $payload, 'text' => 'Voy'],
    ]]]]]]]];
}

function postSigned($test, array $body)
{
    $json = json_encode($body);

    return $test->call('POST', '/api/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, 'secret'),
    ], $json);
}

it('answers the Meta verification challenge', function () {
    $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=42')->assertOk()->assertSee('42');
    $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=42')->assertForbidden();
});

it('rejects unsigned payloads', function () {
    $incident = Incident::factory()->create();

    $this->postJson('/api/webhooks/whatsapp', whatsappReply("ack:{$incident->id}"))->assertForbidden();

    expect($incident->fresh()->acknowledged_at)->toBeNull();
});

it('acknowledges and resolves incidents from guard buttons', function () {
    $incident = Incident::factory()->create();
    $guard = User::factory()->create(['phone' => '+5491155550000']);

    postSigned($this, whatsappReply("ack:{$incident->id}"))->assertOk();

    $incident->refresh();
    expect($incident->status)->toBe(IncidentStatus::Acknowledged)
        ->and($incident->acknowledged_by)->toBe($guard->id)
        ->and($incident->actions()->where('type', 'acknowledged')->value('channel'))->toBe('whatsapp');

    postSigned($this, whatsappReply("false:{$incident->id}"))->assertOk();

    expect($incident->fresh()->resolution)->toBe(IncidentResolution::FalseAlarm);
});
