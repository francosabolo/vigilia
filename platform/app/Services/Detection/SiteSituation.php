<?php

namespace App\Services\Detection;

use App\Enums\IncidentSource;
use App\Models\AccessAuthorization;
use App\Models\DetectionEvent;
use App\Models\Incident;

/**
 * What is going on in the property right now that the AI should weigh: M10 the access camera and people slipping
 * in behind a car, M8 authorized staff working today, M17 another incident open (a possible distraction).
 */
class SiteSituation
{
    public function for(DetectionEvent $event): string
    {
        $camera = $event->camera;
        $site = $camera->site;
        $now = now()->setTimezone($site->timezone ?? config('app.timezone'));
        $notes = [];

        if ($camera->is_access) {
            $notes[] = 'Es la cámara del acceso: también es alerta si alguien entra a pie detrás de un auto, o entran dos autos en una misma apertura del portón.';
        }

        $working = AccessAuthorization::where('site_id', $site->id)->whereIn('kind', ['provider', 'staff'])->whereNull('revoked_at')
            ->whereDate('valid_from', '<=', $now->toDateString())->whereDate('valid_until', '>=', $now->toDateString())
            ->with('resident:id,lot')->get()
            ->filter(fn (AccessAuthorization $authorization) => $authorization->refusal(now()) === null)
            ->take(5)
            ->map(fn (AccessAuthorization $authorization) => mb_strtolower($authorization->notes ?: $authorization::KINDS[$authorization->kind]).": {$authorization->name}".($authorization->resident?->lot ? " (unidad o lote {$authorization->resident->lot})" : ''));
        if ($working->isNotEmpty()) {
            $notes[] = 'Personal autorizado trabajando ahora: '.$working->implode('; ').'. Si coincide con lo que se ve, no es amenaza.';
        }

        $other = Incident::where('site_id', $site->id)->where('source', IncidentSource::Detection)->where('status', '!=', 'resolved')
            ->where('detected_at', '>=', now()->subMinutes(15))->when($event->incident_id, fn ($query) => $query->where('id', '!=', $event->incident_id))
            ->where(fn ($query) => $query->whereNull('camera_id')->orWhere('camera_id', '!=', $camera->id))
            ->exists();
        if ($other) {
            $notes[] = 'Hay otro incidente abierto en la propiedad hace menos de 15 minutos: puede ser una distracción, prestá especial atención.';
        }

        return implode(' ', $notes);
    }
}
