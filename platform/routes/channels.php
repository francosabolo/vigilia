<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return $user->id === $id;
});

Broadcast::channel('operators.{organizationId}', function (User $user, int $organizationId) {
    return $user->isCentral() && $user->organization_id === $organizationId;
});

Broadcast::channel('sites.{siteId}', function (User $user, int $siteId) {
    $site = Site::find($siteId);

    return $site !== null && $user->canAccessSite($site);
});

Broadcast::channel('clients.{clientId}', function (User $user, int $clientId) {
    return $user->isClientAdmin() && $user->client_id === $clientId;
});
