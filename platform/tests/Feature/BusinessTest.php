<?php

use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\Client;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;

it('shows what to bill per client, the SLA compliance and the margin per property', function () {
    $client = Client::factory()->create(['sla_ack_seconds' => 120]);
    $client->organization->update(['price_per_camera' => 10000, 'usd_rate' => 1000, 'operator_hour_cost' => 5000]);
    $site = Site::factory()->create(['organization_id' => $client->organization_id, 'client_id' => $client->id]);
    Camera::factory()->count(3)->create(['site_id' => $site->id]);
    Incident::factory()->create(['site_id' => $site->id, 'detected_at' => now(), 'notified_at' => now(), 'acknowledged_at' => now()->addSeconds(60)]);
    Incident::factory()->create(['site_id' => $site->id, 'detected_at' => now(), 'notified_at' => now(), 'acknowledged_at' => now()->addSeconds(600)]);
    $admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $client->organization_id]);

    $this->actingAs($admin)->get('/business')->assertOk()->assertInertia(fn ($page) => $page
        ->where('report.clients.0.fee', 30000)
        ->where('report.clients.0.sla.percent', 50)
        ->where('report.sites.0.cameras', 3));

    $this->actingAs($admin)->patch("/business/clients/{$client->id}", ['price_per_camera' => 8000, 'sla_ack_seconds' => 300])->assertRedirect();
    expect($client->fresh())->sla_ack_seconds->toBe(300);

    $operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $client->organization_id]);
    $this->actingAs($operator)->get('/business')->assertForbidden();
});
