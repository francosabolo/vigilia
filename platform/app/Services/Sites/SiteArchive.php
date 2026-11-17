<?php

namespace App\Services\Sites;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * K14 — a building or country leaves the service. Its images are deleted (personal data with no purpose left),
 * its edge boxes are revoked, residents lose their accounts and staff assigned only to it are unassigned.
 * The site and its incident records are archived, not erased: they are the security company's history.
 */
class SiteArchive
{
    /**
     * @return array{residents: int, edges: int}
     */
    public function archive(Site $site): array
    {
        return DB::transaction(function () use ($site): array {
            $residents = $site->residents()->count();
            $edges = $site->edgeDevices()->count();

            $site->residents()->delete();
            User::where('site_id', $site->id)->whereIn('role', [UserRole::Guard, UserRole::SiteAdmin])->update(['site_id' => null]);
            $site->edgeDevices()->each(fn ($edge) => $edge->forceFill(['token_hash' => hash('sha256', 'revoked:'.$edge->id.':'.now()->timestamp)])->save());
            $site->cameras()->each(fn ($camera) => $camera->delete());
            $site->forceFill(['patrol_interval_minutes' => null, 'test_mode_until' => null])->save();
            $site->delete();

            Storage::disk(config('vigilia.media_disk'))->deleteDirectory("sites/{$site->id}");

            return ['residents' => $residents, 'edges' => $edges];
        });
    }
}
