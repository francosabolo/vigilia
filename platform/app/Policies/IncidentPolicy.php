<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\User;

class IncidentPolicy
{
    public function view(User $user, Incident $incident): bool
    {
        return $user->canAccessSite($incident->site);
    }

    public function act(User $user, Incident $incident): bool
    {
        return $user->canAccessSite($incident->site);
    }
}
