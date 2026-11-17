<?php

namespace App\Simulation;

use App\Enums\SiteKind;
use App\Enums\UserRole;
use App\Enums\ZoneKind;
use App\Models\Camera;
use App\Models\EdgeDevice;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds simulated gated communities: perimeter on the map, cameras along the fence (with a few gaps on
 * purpose), zones and arming schedules, an edge device, a guard and residents.
 */
class SimulatedWorld
{
    private const NAMES = [
        'Los Robles', 'San Gabriel', 'El Ombú', 'Las Acacias', 'Santa Clara', 'El Lucero', 'Los Fresnos', 'La Herradura',
        'Altos del Sol', 'El Aljibe', 'Los Ceibos', 'Haras del Sur', 'La Comarca', 'El Canal', 'Las Glorias', 'Mirasoles',
        'Santa Bárbara', 'El Recodo', 'Los Talas', 'La Alameda', 'Lomas del Río', 'El Remanso II', 'San Agustín', 'Los Pinos',
    ];

    /** Rough centers in the north and south of Greater Buenos Aires, where gated communities concentrate. */
    private const AREAS = [[-34.42, -58.85], [-34.35, -58.79], [-34.40, -58.65], [-34.87, -58.50], [-34.49, -58.93], [-34.33, -58.97]];

    /**
     * @return array<int, Site>
     */
    public function build(Organization $organization, int $count, int $minCameras, int $maxCameras): array
    {
        $names = collect(self::NAMES)->shuffle()->take($count)->values();

        // Alternate countries and buildings: Vigilia sells to both kinds of consorcio.
        return $names->map(fn (string $name, int $index) => DB::transaction(fn () => $index % 2 === 0
            ? $this->site($organization, "Country {$name}", $index, random_int($minCameras, $maxCameras))
            : $this->building($organization, $index, random_int(6, 12))))->all();
    }

    public function destroy(): int
    {
        $sites = Site::where('is_simulated', true)->get();
        foreach ($sites as $site) {
            User::where('site_id', $site->id)->delete();
            $site->delete();
        }

        return $sites->count();
    }

    private const STREETS = ['Arenales', 'Libertador', 'Cabildo', 'Santa Fe', 'Juncal', 'Billinghurst', 'Gorriti', 'Thames', 'Olazábal', 'Moreno'];

    private const BUILDING_SPOTS = [
        ['Hall de entrada', ZoneKind::Door], ['Cochera', ZoneKind::Door], ['Puerta de servicio', ZoneKind::Door], ['Terraza', ZoneKind::Sensitive],
        ['Sala de máquinas', ZoneKind::Sensitive], ['Lavadero', ZoneKind::Sensitive], ['Patio interno', ZoneKind::Perimeter], ['Palier PB', ZoneKind::Door],
        ['Palier 1° piso', ZoneKind::Door], ['Palier 2° piso', ZoneKind::Door], ['Bauleras', ZoneKind::Sensitive], ['Ascensor', ZoneKind::Sensitive],
    ];

    /**
     * A building: small footprint, cameras on accesses and sensitive points, units like "4B".
     */
    private function building(Organization $organization, int $index, int $cameraCount): Site
    {
        [$baseLat, $baseLng] = [-34.58 + mt_rand(-30, 30) / 1000, -58.42 + mt_rand(-40, 40) / 1000];
        $street = self::STREETS[$index % count(self::STREETS)];
        $name = "Edificio {$street} ".(mt_rand(5, 49) * 50);
        $half = 0.00012;

        $site = Site::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'kind' => SiteKind::Building,
            'address' => "{$street} (simulado), CABA",
            'perimeter' => [[$baseLat + $half, $baseLng - $half * 1.3], [$baseLat + $half, $baseLng + $half * 1.3], [$baseLat - $half, $baseLng + $half * 1.3], [$baseLat - $half, $baseLng - $half * 1.3]],
            'lat' => $baseLat,
            'lng' => $baseLng,
            'is_simulated' => true,
            'ack_timeout_seconds' => 120,
        ]);

        $edge = EdgeDevice::create(['site_id' => $site->id, 'name' => 'edge-porteria', 'token_hash' => hash('sha256', Str::random(40)), 'version' => '0.3.0', 'last_seen_at' => now()]);

        foreach (array_slice(self::BUILDING_SPOTS, 0, $cameraCount) as $i => [$spot, $kind]) {
            $angle = $i / max(1, $cameraCount) * 2 * M_PI;
            $camera = Camera::create([
                'site_id' => $site->id, 'edge_device_id' => $edge->id, 'name' => $spot, 'frigate_name' => "cam_{$i}",
                'sector' => $i < 4 ? 'Planta baja' : 'Pisos',
                'lat' => $baseLat + sin($angle) * $half * 0.8, 'lng' => $baseLng + cos($angle) * $half,
                'heading' => (int) fmod(rad2deg($angle) + 180 + 360, 360), 'fov' => 100, 'range_m' => 15, 'last_seen_at' => now(),
            ]);
            $zone = $camera->zones()->create([
                'name' => $spot, 'kind' => $kind, 'frigate_zone' => 'alambrado',
                'watched_labels' => ['person'], 'coordinates' => [[0.25, 0.2], [0.75, 0.2], [0.75, 0.95], [0.25, 0.95]],
            ]);
            if ($kind === ZoneKind::Door && $spot !== 'Puerta de servicio') {
                $zone->armingSchedules()->create(['days' => [1, 2, 3, 4, 5, 6, 7], 'starts_at' => '23:00', 'ends_at' => '06:00']);
            }
        }

        $slug = Str::slug($name);
        User::create([
            'name' => "Encargado {$name}", 'email' => "encargado.{$slug}@sim.seguria.test", 'password' => Str::random(32),
            'role' => UserRole::Guard, 'organization_id' => $organization->id, 'site_id' => $site->id,
        ]);
        for ($r = 1, $residents = mt_rand(12, 40); $r <= $residents; $r++) {
            User::create([
                'name' => fake('es_AR')->name(), 'email' => "vecino{$r}.{$slug}@sim.seguria.test", 'password' => Str::random(32),
                'role' => UserRole::Resident, 'organization_id' => $organization->id, 'site_id' => $site->id,
                'lot' => mt_rand(1, 12).chr(64 + mt_rand(1, 6)), 'sector' => mt_rand(0, 1) ? 'Planta baja' : 'Pisos',
                'last_login_at' => mt_rand(1, 100) <= 70 ? now()->subHours(mt_rand(1, 400)) : null,
            ]);
        }

        return $site;
    }

    private function site(Organization $organization, string $name, int $index, int $cameraCount): Site
    {
        [$baseLat, $baseLng] = self::AREAS[$index % count(self::AREAS)];
        $lat = $baseLat + (mt_rand(-80, 80) / 1000);
        $lng = $baseLng + (mt_rand(-80, 80) / 1000);
        $halfH = mt_rand(14, 30) / 10000;   // ~300-650 m north-south
        $halfW = mt_rand(20, 45) / 10000;   // ~350-800 m east-west
        $corners = [[$lat + $halfH, $lng - $halfW], [$lat + $halfH * 1.05, $lng + $halfW], [$lat - $halfH, $lng + $halfW * 0.95], [$lat - $halfH * 0.95, $lng - $halfW]];

        $site = Site::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'address' => 'Barrio simulado',
            'perimeter' => $corners,
            'lat' => $lat,
            'lng' => $lng,
            'is_simulated' => true,
            'kind' => SiteKind::Country,
            'ack_timeout_seconds' => 180,
        ]);

        $edge = EdgeDevice::create(['site_id' => $site->id, 'name' => 'edge-guardia', 'token_hash' => hash('sha256', Str::random(40)), 'version' => '0.3.0', 'last_seen_at' => now()]);
        $sides = ['Norte', 'Este', 'Sur', 'Oeste'];

        // Cameras spread along the fence, aimed along it. ~10% of the slots are left empty: blind spots.
        for ($i = 0; $i < $cameraCount; $i++) {
            if (mt_rand(1, 100) <= 10) {
                continue;
            }
            $t = $i / $cameraCount * 4;
            $side = (int) floor($t);
            $f = $t - $side;
            [$a, $b] = [$corners[$side], $corners[($side + 1) % 4]];
            $position = [$a[0] + ($b[0] - $a[0]) * $f, $a[1] + ($b[1] - $a[1]) * $f];
            $heading = (int) round(fmod(rad2deg(atan2(($b[1] - $a[1]) * cos(deg2rad($lat)), $b[0] - $a[0])) + 360, 360));
            $isGate = $i === 0;

            $camera = Camera::create([
                'site_id' => $site->id,
                'edge_device_id' => $edge->id,
                'name' => $isGate ? 'Acceso principal' : "{$sides[$side]} ".($i + 1),
                'frigate_name' => "cam_{$i}",
                'sector' => "Perímetro {$sides[$side]}",
                'lat' => $position[0],
                'lng' => $position[1],
                'heading' => $heading,
                'fov' => 90,
                'range_m' => mt_rand(35, 55),
                'last_seen_at' => now(),
            ]);

            $zone = $camera->zones()->create([
                'name' => $isGate ? 'Portón de acceso' : 'Alambrado',
                'kind' => $isGate ? ZoneKind::Door : ZoneKind::Perimeter,
                'frigate_zone' => 'alambrado',
                'watched_labels' => $isGate ? ['person'] : ['person', 'car', 'motorcycle'],
                'coordinates' => [[0, 0.5], [1, 0.45], [1, 0.85], [0, 0.9]],
            ]);
            if (! $isGate) {
                $zone->armingSchedules()->create(['days' => [1, 2, 3, 4, 5, 6, 7], 'starts_at' => '20:00', 'ends_at' => '07:00']);
            }
        }

        $slug = Str::slug($name);
        User::create([
            'name' => "Guardia {$name}", 'email' => "guardia.{$slug}@sim.seguria.test", 'password' => Str::random(32),
            'role' => UserRole::Guard, 'organization_id' => $organization->id, 'site_id' => $site->id,
        ]);
        for ($r = 1, $residents = mt_rand(20, 60); $r <= $residents; $r++) {
            User::create([
                'name' => fake('es_AR')->name(), 'email' => "vecino{$r}.{$slug}@sim.seguria.test", 'password' => Str::random(32),
                'role' => UserRole::Resident, 'organization_id' => $organization->id, 'site_id' => $site->id,
                'lot' => (string) mt_rand(1, 400), 'sector' => 'Perímetro '.$sides[array_rand($sides)],
                'last_login_at' => mt_rand(1, 100) <= 70 ? now()->subHours(mt_rand(1, 400)) : null,
            ]);
        }

        return $site;
    }
}
