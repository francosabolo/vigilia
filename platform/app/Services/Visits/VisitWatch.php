<?php

namespace App\Services\Visits;

use Anthropic\Client;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Enums\UserRole;
use App\Enums\ZoneKind;
use App\Events\IncidentChanged;
use App\Events\IncidentOpened;
use App\Models\AccessEntry;
use App\Models\DetectionEvent;
use App\Models\Incident;
use App\Models\Site;
use App\Models\User;
use App\Notifications\VisitorLeftWithItems;
use App\Notifications\VisitorOverdue;
use App\Services\Verification\AiBudget;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Visitors are watched for what they do while inside, not for who they are: a known face never silences an alert,
 * because thefts are often done by people let in on purpose.
 *
 * D6 — a visitor seen where a visit has no business (the perimeter, a window, a building site, the parking when
 *      they came on foot); D7 — a visit or a provider still inside after their time; D8 — someone leaving with
 *      things they did not bring.
 */
class VisitWatch
{
    /** Zones a visitor going to a unit has no reason to be in. */
    private const OFF_ROUTE = [ZoneKind::Perimeter, ZoneKind::Window, ZoneKind::Sensitive, ZoneKind::Construction, ZoneKind::Loading, ZoneKind::Hazard];

    public function __construct(private readonly AiBudget $budget) {}

    /**
     * D7 — when the visit should be over: the end of the provider's or staff's hours today, or a few hours.
     */
    public function expectedUntil(AccessEntry $entry, Site $site): CarbonInterface
    {
        $authorization = $entry->authorization;
        if ($authorization?->time_to) {
            $end = now()->setTimezone($site->timezone ?? config('app.timezone'))->setTimeFromTimeString($authorization->time_to);
            if ($end->isFuture()) {
                return $end->utc();
            }
        }

        return now()->addHours(config('vigilia.visits.expected_hours'));
    }

    /**
     * Visitors inside a property right now, with their entry photo.
     *
     * @return Collection<int, AccessEntry>
     */
    public function inside(int $siteId): Collection
    {
        return AccessEntry::where('site_id', $siteId)->where('status', 'allowed')->whereNull('exited_at')
            ->whereNotNull('photo_path')->where('created_at', '>', now()->subHours(16))->latest()->limit(4)->get();
    }

    /**
     * D6 — is the person in this detection one of the visitors inside, in a zone off their route?
     */
    public function checkRoute(DetectionEvent $event): ?Incident
    {
        $event->loadMissing('camera.site', 'camera.zones');
        $camera = $event->camera;
        if ($event->label !== 'person' || ! $event->snapshot_path || $camera->site->is_simulated) {
            return null;
        }

        $visitors = $this->inside($camera->site_id);
        $zones = $camera->zones->filter(fn ($zone) => in_array($zone->frigate_zone, $event->zones ?? [], true));
        $offRoute = $zones->first(fn ($zone) => in_array($zone->kind, self::OFF_ROUTE, true)
            || ($zone->kind === ZoneKind::Garage && $visitors->contains(fn (AccessEntry $entry) => ! $entry->plate)));
        if (! $offRoute || $visitors->isEmpty() || ! Cache::add("visitor-route-{$camera->id}", true, 180)) {
            return null;
        }

        $disk = Storage::disk(config('vigilia.media_disk'));
        $photos = $visitors->filter(fn (AccessEntry $entry) => $disk->exists($entry->photo_path))->values();
        if ($photos->isEmpty() || ! $disk->exists($event->snapshot_path) || ! config('vigilia.ai.enabled') || ! $this->budget->consume($camera->site_id)) {
            return null;
        }

        $content = [['type' => 'text', 'text' => "Detección en {$camera->name}, zona {$offRoute->name} ({$offRoute->kind->label()}):"], $this->image($disk->get($event->snapshot_path))];
        foreach ($photos as $index => $entry) {
            $content[] = ['type' => 'text', 'text' => 'Foto de entrada '.($index + 1).": {$entry->name}, entró ".$entry->created_at->diffForHumans().'.'];
            $content[] = $this->image($disk->get($entry->photo_path));
        }
        $content[] = ['type' => 'text', 'text' => '¿La persona de la detección es alguna de las visitas de las fotos de entrada? Compará ropa, contextura y lo que lleva; no identifiques por la cara.'];

        $verdict = $this->ask($content, VisitorMatch::class, 'Comparás personas en cámaras de seguridad por su ropa y apariencia general, nunca por rasgos faciales. Respondés 0 si no hay coincidencia clara.');
        if (! $verdict instanceof VisitorMatch || $verdict->match < 1 || $verdict->match > $photos->count() || $verdict->confidence < 0.6) {
            return null;
        }

        $visitor = $photos[$verdict->match - 1];
        $where = "{$offRoute->name} ({$camera->name})";
        $note = "Parece ser {$visitor->name}, que entró ".$visitor->created_at->format('H:i').' para '.($visitor->unit ? "la {$visitor->unit}" : 'una visita').". {$verdict->description}";

        // Already an incident for this movement: add who it may be, so the guard knows where they came from.
        if ($event->fresh()->incident_id && $incident = Incident::find($event->fresh()->incident_id)) {
            $incident->actions()->create(['type' => 'visitor_match', 'channel' => 'ai', 'notes' => $note, 'meta' => ['access_entry_id' => $visitor->id]]);
            IncidentChanged::dispatch($incident, 'visitor');

            return $incident;
        }

        $incident = Incident::create([
            'site_id' => $camera->site_id, 'camera_id' => $camera->id, 'source' => IncidentSource::Detection, 'sector' => $camera->sector,
            'severity' => Severity::High, 'status' => IncidentStatus::New, 'detected_at' => now(),
            'title' => "Visita fuera de su recorrido: {$visitor->name} en {$where}",
            'description' => $note, 'snapshot_path' => $event->snapshot_path,
        ]);
        $incident->actions()->create(['type' => 'visitor_match', 'channel' => 'ai', 'notes' => $note, 'meta' => ['access_entry_id' => $visitor->id, 'confidence' => $verdict->confidence]]);
        $event->forceFill(['incident_id' => $incident->id])->save();
        IncidentOpened::dispatch($incident);
        IncidentChanged::dispatch($incident, 'opened');

        return $incident;
    }

    /**
     * D7 — visits and providers still inside after their time: the guard is told once.
     */
    public function notifyOverdue(): int
    {
        $count = 0;
        AccessEntry::query()->where('status', 'allowed')->whereNull('exited_at')->whereNull('overdue_notified_at')
            ->whereNotNull('expected_until')->where('expected_until', '<', now())->where('created_at', '>', now()->subDay())
            ->with('site')->get()
            ->each(function (AccessEntry $entry) use (&$count) {
                $entry->forceFill(['overdue_notified_at' => now()])->save();
                Notification::send($this->guardsOf($entry->site), new VisitorOverdue($entry));
                $count++;
            });

        return $count;
    }

    /**
     * D8 — on the way out, compare with the entry photo; the resident who received them hears about it.
     */
    public function checkExit(AccessEntry $entry): void
    {
        $disk = Storage::disk(config('vigilia.media_disk'));
        if (! $entry->photo_path || ! $entry->exit_photo_path || ! $disk->exists($entry->photo_path) || ! $disk->exists($entry->exit_photo_path)
            || ! config('vigilia.ai.enabled') || ! $this->budget->consume($entry->site_id)) {
            return;
        }

        $verdict = $this->ask([
            ['type' => 'text', 'text' => "Entrada de {$entry->name}:"], $this->image($disk->get($entry->photo_path)),
            ['type' => 'text', 'text' => 'Salida de la misma persona:'], $this->image($disk->get($entry->exit_photo_path)),
            ['type' => 'text', 'text' => '¿Sale llevando cosas que no tenía al entrar?'],
        ], ExitCheck::class, 'Comparás la foto de entrada y de salida de una visita en la guardia de un edificio o country. Lo que lleva puesto o una mochila que ya tenía no cuenta.');

        if (! $verdict instanceof ExitCheck) {
            return;
        }
        $entry->forceFill(['exit_check' => ['carries_more' => $verdict->carries_more, 'description' => $verdict->description]])->save();
        if ($verdict->carries_more && $entry->resident) {
            $entry->resident->notify(new VisitorLeftWithItems($entry));
        }
    }

    /**
     * @return Collection<int, User>
     */
    public function guardsOf(Site $site): Collection
    {
        $guards = User::where('site_id', $site->id)->where('role', UserRole::Guard)->get();

        return $guards->isNotEmpty() ? $guards : User::where('organization_id', $site->organization_id)->whereIn('role', [UserRole::Operator, UserRole::Admin])->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function image(string $bytes): array
    {
        return ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => base64_encode($bytes)]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $content
     * @param  class-string  $format
     */
    protected function ask(array $content, string $format, string $system): mixed
    {
        try {
            return app(Client::class)->messages->create(
                model: config('vigilia.ai.review_model'),
                maxTokens: 400,
                system: $system,
                messages: [['role' => 'user', 'content' => $content]],
                outputConfig: ['format' => $format, 'effort' => 'low'],
            )->parsedOutput();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
