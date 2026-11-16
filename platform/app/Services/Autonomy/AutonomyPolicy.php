<?php

namespace App\Services\Autonomy;

use App\Enums\IncidentResolution;
use App\Enums\IncidentSource;
use App\Enums\UserRole;
use App\Enums\ZoneAutonomy;
use App\Models\DetectionEvent;
use App\Models\DeterrenceCommand;
use App\Models\Incident;
use App\Models\User;
use App\Models\Zone;
use App\Notifications\ZoneAutonomyDemoted;
use App\Services\Verification\VerificationResult;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Autonomy levels per zone, earned with data: a zone gets automatic deterrence only after enough closed alerts
 * with few false alarms, and loses it on its own when deterrence fires by mistake.
 */
class AutonomyPolicy
{
    /**
     * @return array{eligible: bool, alerts: int, false_alarms: int, false_alarm_rate: ?float, reason: string}
     */
    public function eligibility(Zone $zone): array
    {
        $config = config('seguria.autonomy');
        $closed = Incident::query()
            ->where('zone_id', $zone->id)
            ->where('source', IncidentSource::Detection)
            ->whereIn('resolution', [IncidentResolution::Confirmed, IncidentResolution::FalseAlarm])
            ->where('resolved_at', '>=', now()->subDays($config['eligibility_days']))
            ->pluck('resolution');

        $alerts = $closed->count();
        $falseAlarms = $closed->filter(fn ($resolution) => $resolution === IncidentResolution::FalseAlarm)->count();
        $rate = $alerts > 0 ? round($falseAlarms / $alerts, 4) : null;

        $reason = match (true) {
            $alerts < $config['eligibility_min_alerts'] => sprintf('Necesita al menos %d alertas cerradas en %d días; tiene %d.', $config['eligibility_min_alerts'], $config['eligibility_days'], $alerts),
            $rate > $config['eligibility_max_false_alarm_rate'] => sprintf('Tiene %d%% de falsas alarmas; el máximo es %d%%.', round($rate * 100), round($config['eligibility_max_false_alarm_rate'] * 100)),
            default => sprintf('%d alertas cerradas en %d días, %d%% falsas alarmas.', $alerts, $config['eligibility_days'], round(($rate ?? 0) * 100)),
        };

        return [
            'eligible' => $alerts >= $config['eligibility_min_alerts'] && $rate <= $config['eligibility_max_false_alarm_rate'],
            'alerts' => $alerts,
            'false_alarms' => $falseAlarms,
            'false_alarm_rate' => $rate,
            'reason' => $reason,
        ];
    }

    public function set(Zone $zone, ZoneAutonomy $level): void
    {
        if ($level === ZoneAutonomy::Deterrence && ! $this->eligibility($zone)['eligible']) {
            throw ValidationException::withMessages(['autonomy' => 'Esta zona todavía no ganó la disuasión automática. '.$this->eligibility($zone)['reason']]);
        }

        $zone->forceFill(['autonomy' => $level, 'autonomy_changed_at' => now()])->save();
    }

    /**
     * Automatic deterrence: the camera speaks (or the siren sounds) before the guard arrives. Only on clear
     * threats from a person, once per incident, in zones that earned it.
     */
    public function deterIfEnabled(Incident $incident, DetectionEvent $event, VerificationResult $verdict, ?Zone $zone): ?DeterrenceCommand
    {
        if ($zone?->autonomy !== ZoneAutonomy::Deterrence
            || $event->label !== 'person'
            || $verdict->confidence === null
            || $verdict->confidence < config('seguria.autonomy.deterrence_confidence')
            || DeterrenceCommand::where('incident_id', $incident->id)->exists()) {
            return null;
        }

        $command = DeterrenceCommand::create([
            'incident_id' => $incident->id,
            'edge_device_id' => $event->edge_device_id,
            'camera_id' => $event->camera_id,
            'message' => config('seguria.autonomy.deterrence_message'),
        ]);

        $incident->actions()->create([
            'type' => 'deterred',
            'channel' => 'system',
            'notes' => "Disuasión automática en {$event->camera->name}: \"{$command->message}\"",
            'meta' => ['deterrence_command_id' => $command->id],
        ]);

        return $command;
    }

    /**
     * A deterrence that turned out to be a false alarm counts against the zone; two in a week demote it.
     */
    public function afterResolved(Incident $incident): void
    {
        if ($incident->resolution !== IncidentResolution::FalseAlarm || ! $incident->zone || $incident->zone->autonomy !== ZoneAutonomy::Deterrence) {
            return;
        }

        $config = config('seguria.autonomy');
        $mistakes = DeterrenceCommand::query()
            ->whereHas('incident', fn ($query) => $query
                ->where('zone_id', $incident->zone_id)
                ->where('resolution', IncidentResolution::FalseAlarm)
                ->where('resolved_at', '>=', now()->subDays($config['demotion_window_days'])))
            ->count();

        if ($mistakes < $config['demotion_false_alarms']) {
            return;
        }

        $zone = $incident->zone;
        $zone->forceFill(['autonomy' => ZoneAutonomy::Assisted, 'autonomy_changed_at' => now()])->save();
        $incident->actions()->create([
            'type' => 'autonomy_demoted',
            'channel' => 'system',
            'notes' => "La zona {$zone->name} volvió a Asistida: la disuasión se activó {$mistakes} veces por falsas alarmas en {$config['demotion_window_days']} días.",
        ]);

        $admins = User::where('organization_id', $incident->site->organization_id)->where('role', UserRole::Admin)->get();
        Notification::send($admins, new ZoneAutonomyDemoted($zone, $mistakes));
    }
}
