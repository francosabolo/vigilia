<?php

namespace App\Http\Controllers;

use App\Models\AccessAuthorization;
use App\Models\AccessEntry;
use App\Models\Site;
use App\Models\User;
use App\Models\WatchedPlate;
use App\Services\Access\AccessDesk;
use App\Services\Privacy\MediaAccessLog;
use App\Services\Sites\SiteScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The gate screen: check codes, announce visitors without notice, manage providers and domestic staff, and the
 * log of who came in (D1–D3).
 */
class AccessController extends Controller
{
    public function index(Request $request, SiteScope $scope): Response
    {
        $site = $scope->current($request) ?? (count($scope->siteIds($request)) === 1 ? Site::find($scope->siteIds($request)[0]) : null);

        if (! $site) {
            return Inertia::render('access/index', ['site' => null, 'sites' => Site::whereIn('id', $scope->siteIds($request))->orderBy('name')->get(['id', 'name'])]);
        }

        Gate::authorize('view', $site);
        $q = trim((string) $request->query('q'));

        return Inertia::render('access/index', [
            'site' => ['id' => $site->id, 'name' => $site->name, 'kind' => $site->kind->value, 'has_access_camera' => $site->cameras()->where('is_access', true)->exists()],
            'waiting' => AccessEntry::where('site_id', $site->id)->where('status', 'waiting')->with('resident:id,name,lot')->latest()->get()->map->toPayload(),
            'inside' => AccessEntry::where('site_id', $site->id)->where('status', 'allowed')->whereNull('exited_at')
                ->where('created_at', '>', now()->subHours(16))->with('resident:id,name,lot')->latest()->get()->map->toPayload(),
            'entries' => AccessEntry::where('site_id', $site->id)
                ->when($unit = trim((string) $request->query('unit')), fn ($query) => $query->where(fn ($query) => $query->where('unit', $unit)->orWhereHas('resident', fn ($q) => $q->where('lot', $unit))))
                ->when($q, fn ($query) => $query->where(fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%'])->orWhereRaw('LOWER(COALESCE(visiting, \'\')) LIKE ?', ['%'.mb_strtolower($q).'%'])->orWhere('plate', 'like', '%'.strtoupper($q).'%')))
                ->with(['resident:id,name,lot', 'guardUser:id,name'])->latest()->limit(50)->get()->map->toPayload(),
            'authorizations' => AccessAuthorization::where('site_id', $site->id)->whereNull('revoked_at')->where('valid_until', '>=', now()->toDateString())
                ->with('resident:id,name,lot', 'site:id,timezone')->orderBy('kind')->orderBy('name')->get()->map->toPayload(),
            'watchlist' => WatchedPlate::where('site_id', $site->id)->orderBy('plate')->get(['id', 'plate', 'reason']),
            'residents' => $site->residents()->get(['id', 'name', 'lot'])->map(fn (User $resident) => ['id' => $resident->id, 'name' => $resident->name, 'lot' => $resident->lot]),
            'q' => $q,
            'unit' => trim((string) $request->query('unit')),
            'units' => $site->residents()->whereNotNull('lot')->distinct()->orderBy('lot')->pluck('lot'),
        ]);
    }

    public function checkIn(Request $request, Site $site, AccessDesk $desk): RedirectResponse
    {
        Gate::authorize('view', $site);
        $data = $request->validate(['code' => ['required', 'string', 'max:64'], 'plate' => ['nullable', 'string', 'max:15']]);

        $entry = $desk->checkIn($site, trim($data['code']), $request->user(), ! empty($data['plate']) ? strtoupper($data['plate']) : null);

        return back()->with('notice', $entry->status === 'allowed' ? "Puede pasar: {$entry->name}." : "No puede pasar: {$entry->reason}");
    }

    public function announce(Request $request, Site $site, AccessDesk $desk): RedirectResponse
    {
        Gate::authorize('view', $site);
        $data = $request->validate([
            'resident_id' => ['required', Rule::exists('users', 'id')->where('site_id', $site->id)->where('role', 'resident')],
            'name' => ['required', 'string', 'max:100'],
            'plate' => ['nullable', 'string', 'max:15'],
        ], ['resident_id.required' => 'Elegí a qué vecino visita.']);

        $desk->announce($site, $request->user(), User::findOrFail($data['resident_id']), $data['name'], ! empty($data['plate']) ? strtoupper($data['plate']) : null);

        return back()->with('notice', 'Le avisamos al vecino. Su respuesta aparece acá.');
    }

    /**
     * D5 — register a visit: let in now, or ask the resident first.
     */
    public function registerVisit(Request $request, Site $site, AccessDesk $desk): RedirectResponse
    {
        Gate::authorize('view', $site);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'document' => ['nullable', 'string', 'max:30'],
            'plate' => ['nullable', 'string', 'max:15'],
            'unit' => ['required', 'string', 'max:20'],
            'resident_id' => ['nullable', Rule::exists('users', 'id')->where('site_id', $site->id)->where('role', 'resident')],
            'visiting' => ['nullable', 'string', 'max:100'],
            'purpose' => ['nullable', 'string', 'max:150'],
            'ask' => ['boolean'],
        ], [
            'name.required' => 'Escribí el nombre de quien llega.',
            'unit.required' => 'Escribí a qué unidad va.',
        ]);

        $entry = $desk->registerVisit($site, $request->user(), $data, (bool) ($data['ask'] ?? false));

        return back()->with('notice', $entry->status === 'waiting'
            ? "Le avisamos a {$entry->visiting}. Su respuesta aparece acá."
            : "Registrado: {$entry->name} a {$entry->unit}".($entry->visiting ? " ({$entry->visiting})" : '').'.');
    }

    public function markExit(AccessEntry $accessEntry, AccessDesk $desk): RedirectResponse
    {
        Gate::authorize('view', $accessEntry->site);
        $desk->exit($accessEntry);

        return back();
    }

    /**
     * The guard answers for the resident (they called on the phone, or they are not answering).
     */
    public function answer(Request $request, AccessEntry $accessEntry, AccessDesk $desk): RedirectResponse
    {
        Gate::authorize('view', $accessEntry->site);
        $data = $request->validate(['allow' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:200']]);

        $desk->answer($accessEntry, $data['allow'], $request->user(), $data['reason'] ?? ($data['allow'] ? 'Autorizado por la guardia.' : null));

        return back();
    }

    public function storeAuthorization(Request $request, Site $site, AccessDesk $desk): RedirectResponse
    {
        Gate::authorize('view', $site);
        $data = $this->validatedAuthorization($request, $site);
        $resident = isset($data['resident_id']) ? User::find($data['resident_id']) : null;
        unset($data['resident_id']);

        $authorization = $desk->authorize($site, $data, $request->user(), $resident);

        return back()->with('notice', "Autorizado. Código: {$authorization->code}.");
    }

    public function revoke(AccessAuthorization $authorization): RedirectResponse
    {
        Gate::authorize('view', $authorization->site);
        $authorization->update(['revoked_at' => now()]);

        return back();
    }

    public function watchPlate(Request $request, Site $site): RedirectResponse
    {
        Gate::authorize('manage', $site);
        $data = $request->validate(['plate' => ['required', 'string', 'max:15'], 'reason' => ['nullable', 'string', 'max:200']]);

        WatchedPlate::updateOrCreate(['site_id' => $site->id, 'plate' => WatchedPlate::normalize($data['plate'])], ['reason' => $data['reason'] ?? null, 'created_by' => $request->user()->id]);

        return back();
    }

    public function unwatchPlate(WatchedPlate $plate): RedirectResponse
    {
        Gate::authorize('manage', Site::findOrFail($plate->site_id));
        $plate->delete();

        return back();
    }

    public function photo(Request $request, AccessEntry $accessEntry, MediaAccessLog $log): StreamedResponse
    {
        $disk = Storage::disk(config('seguria.media_disk'));
        abort_unless($accessEntry->photo_path && $disk->exists($accessEntry->photo_path), 404);
        $log->record($request, $accessEntry->site_id, 'access', "Ingreso de {$accessEntry->name}", "access:{$accessEntry->id}");

        return $disk->response($accessEntry->photo_path, headers: ['Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function validatedAuthorization(Request $request, Site $site, bool $resident = false): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in($resident ? ['visit', 'provider', 'staff'] : array_keys(AccessAuthorization::KINDS))],
            'name' => ['required', 'string', 'max:100'],
            'document' => ['nullable', 'string', 'max:30'],
            'plate' => ['nullable', 'string', 'max:15'],
            ...($resident ? [] : ['resident_id' => ['nullable', Rule::exists('users', 'id')->where('site_id', $site->id)->where('role', 'resident')]]),
            // "Today" at the property, not on the server.
            'valid_from' => ['required', 'date', 'after_or_equal:'.now($site->timezone ?? config('app.timezone'))->toDateString()],
            'valid_until' => ['required', 'date', 'after_or_equal:valid_from', 'before_or_equal:'.now()->addYear()->toDateString()],
            'days' => ['nullable', 'array'],
            'days.*' => ['integer', 'between:1,7'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to' => ['nullable', 'date_format:H:i', 'after:time_from'],
            'notes' => ['nullable', 'string', 'max:200'],
        ]);
    }
}
