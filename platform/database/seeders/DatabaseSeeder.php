<?php

namespace Database\Seeders;

use App\Enums\DetectionStatus;
use App\Enums\IncidentResolution;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\DetectionEvent;
use App\Models\EdgeDevice;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data: one organization, two gated communities, staff for every role and a week of history.
 * All demo users have the password "password".
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::firstOrCreate(['slug' => 'seguria'], ['name' => 'Seguria Central']);

        $site = Site::firstOrCreate(['name' => 'Barrio Los Álamos'], [
            'organization_id' => $organization->id,
            'address' => 'Ruta 8 km 52, Pilar',
            'ack_timeout_seconds' => 180,
        ]);
        $second = Site::firstOrCreate(['name' => 'Country El Remanso'], [
            'organization_id' => $organization->id,
            'address' => 'Ruta 6 km 12, Cardales',
        ]);

        $users = [
            ['admin@seguria.test', 'Admin Seguria', UserRole::Admin, null],
            ['central@seguria.test', 'Operador Central', UserRole::Operator, null],
            ['guardia@seguria.test', 'Guardia Los Álamos', UserRole::Guard, $site->id],
            ['admin.alamos@seguria.test', 'Administración Los Álamos', UserRole::SiteAdmin, $site->id],
            ['vecino@seguria.test', 'Laura Gómez', UserRole::Resident, $site->id],
        ];

        foreach ($users as [$email, $name, $role, $siteId]) {
            User::updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'email_verified_at' => now(),
                'role' => $role,
                'organization_id' => $organization->id,
                'site_id' => $siteId,
                'lot' => $role === UserRole::Resident ? '42' : null,
                'sector' => $role === UserRole::Resident ? 'Muro sur' : null,
            ]);
        }

        // Map: a ~350 x 220 m gated community near Pilar, and one in Cardales.
        $layouts = [
            $site->id => ['center' => [-34.45870, -58.91420], 'cameras' => ['perimetro_norte' => [-34.45770, -58.91600, 90], 'perimetro_sur' => [-34.45970, -58.91240, 270]]],
            $second->id => ['center' => [-34.31840, -59.01960], 'cameras' => ['perimetro_norte' => [-34.31740, -59.02140, 90], 'perimetro_sur' => [-34.31940, -59.01780, 270]]],
        ];

        foreach ([$site, $second] as $community) {
            [$lat, $lng] = $layouts[$community->id]['center'];
            $community->update([
                'lat' => $lat,
                'lng' => $lng,
                'perimeter' => [[$lat + 0.0010, $lng - 0.0019], [$lat + 0.0010, $lng + 0.0019], [$lat - 0.0010, $lng + 0.0019], [$lat - 0.0010, $lng - 0.0019]],
            ]);

            $edge = EdgeDevice::firstOrCreate(['site_id' => $community->id, 'name' => 'edge-guardia'], [
                'token_hash' => hash('sha256', 'demo-'.$community->id),
                'last_seen_at' => now(),
                'version' => '0.1.0',
            ]);

            foreach ([['perimetro_norte', 'Perímetro Norte', 'Alambrado norte'], ['perimetro_sur', 'Perímetro Sur', 'Muro sur']] as [$frigate, $name, $sector]) {
                [$cameraLat, $cameraLng, $heading] = $layouts[$community->id]['cameras'][$frigate];
                $camera = Camera::firstOrCreate(['edge_device_id' => $edge->id, 'frigate_name' => $frigate], [
                    'site_id' => $community->id,
                    'name' => $name,
                    'sector' => $sector,
                    'lat' => $cameraLat,
                    'lng' => $cameraLng,
                    'heading' => $heading,
                    'fov' => 90,
                    'range_m' => 60,
                    'last_seen_at' => now(),
                ]);

                $zone = $camera->zones()->firstOrCreate(['frigate_zone' => 'alambrado'], [
                    'name' => 'Alambrado',
                    'watched_labels' => ['person', 'car', 'motorcycle'],
                    'coordinates' => [[0, 0.5], [1, 0.45], [1, 0.8], [0, 0.85]],
                ]);

                // The south wall borders an empty lot: armed 24/7. The north fence only at night.
                if ($frigate === 'perimetro_norte' && $zone->armingSchedules()->doesntExist()) {
                    $zone->armingSchedules()->create(['days' => [1, 2, 3, 4, 5, 6, 7], 'starts_at' => '20:00', 'ends_at' => '07:00']);
                }
            }
        }

        if (Incident::where('site_id', $site->id)->doesntExist()) {
            $this->history($site);
        }
    }

    /**
     * A believable last week: lots of raw detections, few real incidents.
     */
    private function history(Site $site): void
    {
        $cameras = $site->cameras()->get();
        $edge = $site->edgeDevices()->first();
        $guard = User::where('email', 'guardia@seguria.test')->first();

        for ($i = 0; $i < 60; $i++) {
            $camera = $cameras->random();
            DetectionEvent::factory()->create([
                'edge_device_id' => $edge->id,
                'camera_id' => $camera->id,
                'label' => fake()->randomElement(['person', 'person', 'dog', 'car']),
                'zones' => ['alambrado'],
                'started_at' => now()->subDays(rand(0, 6))->setTime(rand(0, 5), rand(0, 59)),
                'status' => fake()->randomElement([DetectionStatus::Dismissed, DetectionStatus::Dismissed, DetectionStatus::Ignored]),
                'ai_verdict' => ['is_threat' => false, 'description' => 'Perro cruzando frente al alambrado.'],
            ]);
        }

        $scenarios = [
            ['Persona en Alambrado (Perímetro Norte)', '1 persona caminando pegada al alambrado norte, mira hacia adentro del barrio.', Severity::High, IncidentResolution::FalseAlarm, 3],
            ['Persona en Alambrado (Perímetro Sur)', '2 personas trepando el muro sur, una con mochila.', Severity::Critical, IncidentResolution::Confirmed, 1],
            ['Auto en Alambrado (Perímetro Norte)', 'Auto detenido con luces apagadas junto al alambrado norte.', Severity::Medium, null, 0],
        ];

        foreach ($scenarios as [$title, $description, $severity, $resolution, $daysAgo]) {
            $camera = $cameras->firstWhere('name', str_contains($title, 'Sur') ? 'Perímetro Sur' : 'Perímetro Norte');
            $detectedAt = now()->subDays($daysAgo)->setTime(3, 12);
            $isToday = $daysAgo === 0;

            $incident = Incident::create([
                'site_id' => $site->id,
                'camera_id' => $camera->id,
                'zone_id' => $camera->zones()->value('id'),
                'severity' => $severity,
                'status' => $resolution ? IncidentStatus::Resolved : IncidentStatus::Escalated,
                'title' => $title,
                'description' => $description,
                'ai_confidence' => 0.86,
                'detected_at' => $isToday ? now()->subMinutes(6) : $detectedAt,
                'notified_at' => $isToday ? now()->subMinutes(6) : $detectedAt,
                'acknowledged_at' => $resolution ? $detectedAt->copy()->addSeconds(rand(40, 150)) : null,
                'acknowledged_by' => $resolution ? $guard->id : null,
                'escalated_at' => $isToday ? now()->subMinutes(3) : null,
                'resolved_at' => $resolution ? $detectedAt->copy()->addMinutes(9) : null,
                'resolution' => $resolution,
            ]);

            DetectionEvent::factory()->create([
                'edge_device_id' => $edge->id,
                'camera_id' => $camera->id,
                'zones' => ['alambrado'],
                'started_at' => $incident->detected_at,
                'status' => DetectionStatus::Threat,
                'incident_id' => $incident->id,
            ]);

            $incident->actions()->create(['type' => 'detected', 'channel' => 'ai', 'notes' => $description, 'created_at' => $incident->detected_at]);
            $incident->actions()->create(['type' => 'notified', 'meta' => ['channels' => ['guards']], 'created_at' => $incident->notified_at]);

            if ($resolution) {
                $incident->actions()->create(['type' => 'acknowledged', 'user_id' => $guard->id, 'channel' => 'whatsapp', 'created_at' => $incident->acknowledged_at]);
                $incident->actions()->create([
                    'type' => $resolution === IncidentResolution::Confirmed ? 'confirmed' : 'false_alarm',
                    'user_id' => $guard->id,
                    'channel' => 'whatsapp',
                    'notes' => $resolution === IncidentResolution::Confirmed ? 'Se dio aviso al 911, móvil en camino.' : 'Era un vecino paseando el perro por afuera.',
                    'created_at' => $incident->resolved_at,
                ]);
            } else {
                $incident->actions()->create(['type' => 'escalated', 'channel' => 'system', 'notes' => 'Sin respuesta de la guardia: escalado a la central.', 'created_at' => $incident->escalated_at]);
            }
        }
    }
}
