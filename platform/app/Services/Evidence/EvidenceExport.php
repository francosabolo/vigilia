<?php

namespace App\Services\Evidence;

use App\Models\Incident;
use App\Models\Patrol;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * M19 — the evidence of an incident for the police or a court: the original images and clip as stored, the full
 * record, and a manifest with the SHA-256 of every file and who exported it, so anyone can check nothing changed.
 */
class EvidenceExport
{
    public function build(Incident $incident, User $by): string
    {
        $disk = Storage::disk(config('seguria.media_disk'));
        $files = collect([$incident->snapshot_path, $incident->clip_path])
            ->merge($incident->detectionEvents()->get()->flatMap(fn ($event) => array_filter([$event->snapshot_path, $event->clip_path, ...($event->frame_paths ?? [])])))
            ->merge($incident->snapshotRequests()->pluck('snapshot_path'))
            ->merge(Patrol::where('incident_id', $incident->id)->get()->flatMap(fn (Patrol $patrol) => collect($patrol->findings ?? [])->pluck('frame_path')))
            ->filter()->unique()->filter(fn ($path) => $disk->exists($path))->values();

        $zipPath = tempnam(sys_get_temp_dir(), 'evidence').'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('No se pudo armar el archivo de evidencia.');
        }

        $manifest = [];
        foreach ($files as $index => $path) {
            $bytes = $disk->get($path);
            $name = sprintf('%02d-%s', $index + 1, basename($path));
            $zip->addFromString("archivos/{$name}", $bytes);
            $manifest[] = hash('sha256', $bytes)."  archivos/{$name}";
        }

        $record = [
            'incidente' => $incident->id,
            'titulo' => $incident->title,
            'lugar' => $incident->site->name.($incident->site->address ? ', '.$incident->site->address : ''),
            'detectado' => $incident->detected_at->toIso8601String(),
            'cerrado' => $incident->resolved_at?->toIso8601String(),
            'resultado' => $incident->resolution?->value,
            'descripcion_de_la_ia' => $incident->description,
            'registro' => $incident->actions()->with('user:id,name')->orderBy('id')->get()->map(fn ($action) => [
                'hora' => $action->created_at?->toIso8601String(), 'tipo' => $action->type, 'quien' => $action->user?->name, 'detalle' => $action->notes,
            ]),
        ];
        $recordJson = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $zip->addFromString('registro.json', $recordJson);
        $manifest[] = hash('sha256', $recordJson).'  registro.json';

        $zip->addFromString('MANIFIESTO.txt', implode("\n", [
            "Evidencia del incidente {$incident->id} exportada por {$by->name} ({$by->email}) el ".now()->toIso8601String().'.',
            'Cada línea tiene el SHA-256 del archivo: si el archivo cambia, el valor no coincide.',
            '',
            ...$manifest,
        ])."\n");
        $zip->close();

        return $zipPath;
    }
}
