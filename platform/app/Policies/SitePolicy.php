<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function view(User $user, Site $site): bool
    {
        return $user->canAccessSite($site);
    }

    /**
     * Zones, schedules and cameras can be configured by central staff and the site's administrator.
     */
    public function manage(User $user, Site $site): bool
    {
        return $user->canAccessSite($site) && ($user->isCentral() || $user->role === UserRole::SiteAdmin);
    }
}
