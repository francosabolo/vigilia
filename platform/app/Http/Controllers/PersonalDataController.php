<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\AccessAuthorization;
use App\Models\AccessEntry;
use App\Models\Incident;
use App\Models\MediaView;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * J5 — Ley 25.326: a person asks what data the company keeps about them, or asks to have it deleted.
 */
class PersonalDataController extends Controller
{
    public function export(Request $request, User $user): JsonResponse
    {
        $this->authorizeFor($request, $user);

        $data = [
            'generado' => now()->toIso8601String(),
            'cuenta' => $user->only(['name', 'email', 'phone', 'role', 'lot', 'sector', 'away_until', 'last_login_at', 'created_at']),
            'propiedad' => $user->site?->name,
            'contactos_de_emergencia' => $user->contacts()->get(['name', 'relation', 'phone']),
            'visitas_autorizadas' => AccessAuthorization::where('resident_id', $user->id)->get(['kind', 'name', 'plate', 'valid_from', 'valid_until', 'created_at']),
            'ingresos_a_su_nombre' => AccessEntry::where('resident_id', $user->id)->get(['name', 'plate', 'status', 'created_at']),
            'avisos_que_hizo' => Incident::where('reported_by', $user->id)->get(['title', 'description', 'detected_at', 'status']),
            'notificaciones' => $user->notifications()->get(['data', 'created_at', 'read_at']),
            'imagenes_que_abrio' => MediaView::where('user_id', $user->id)->get(['kind', 'subject', 'viewed_at']),
        ];

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="datos-'.str($user->name)->slug().'.json"',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Deletes the account and its personal data. Incidents the person reported stay for the security history,
     * without their name.
     */
    public function erase(Request $request, User $user): RedirectResponse
    {
        $this->authorizeFor($request, $user);
        abort_if($user->is($request->user()), 422, 'No podés borrar tu propia cuenta desde acá.');
        $request->validate(['confirm' => ['required', Rule::in([$user->name])]], ['confirm.in' => 'Escribí el nombre exacto para confirmar.']);

        Incident::where('reported_by', $user->id)->update(['reported_by' => null]);
        AccessEntry::where('resident_id', $user->id)->update(['resident_id' => null]);
        AccessAuthorization::where('resident_id', $user->id)->update(['revoked_at' => now()]);
        $user->notifications()->delete();
        $user->delete();

        return to_route('team.index')->with('notice', 'Datos borrados.');
    }

    private function authorizeFor(Request $request, User $user): void
    {
        abort_unless($request->user()->role === UserRole::Admin && $user->organization_id === $request->user()->organization_id, 404);
    }
}
