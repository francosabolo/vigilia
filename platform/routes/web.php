<?php

use App\Http\Controllers\CameraWallController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CurrentSiteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EdgeDeviceController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentMediaController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PatrolController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ResidentAdminController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ShadowController;
use App\Http\Controllers\SiteConfigurationController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteSetupController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\ZoneEditorController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('signed')->group(function () {
    Route::get('media/incidents/{incident}/{kind}', IncidentMediaController::class)
        ->whereIn('kind', ['snapshot', 'clip', 'before'])
        ->name('incidents.media');
    Route::get('media/live-frames/{snapshotRequest}', [IncidentMediaController::class, 'liveFrame'])->name('snapshots.media');
    Route::get('media/detections/{detectionEvent}', [IncidentMediaController::class, 'detection'])->name('detections.media');
    Route::get('media/patrols/{patrol}/{camera}', [PatrolController::class, 'frame'])->whereNumber('camera')->name('patrols.frame');
});

Route::middleware('auth')->group(function () {
    // In-app inbox: staff and residents alike.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/latest', [NotificationController::class, 'latest'])->name('notifications.latest');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::get('notifications/{notification}', [NotificationController::class, 'open'])->name('notifications.open');

    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');

    Route::middleware('resident')->prefix('vecinos')->name('residents.')->group(function () {
        Route::get('/', [ResidentController::class, 'home'])->name('home');
        Route::post('panic', [ResidentController::class, 'panic'])->middleware('throttle:5,1')->name('panic');
        Route::post('report', [ResidentController::class, 'report'])->middleware('throttle:10,1')->name('report');
        Route::put('away', [ResidentController::class, 'updateAway'])->name('away');
    });

    Route::middleware(['staff', 'two-factor'])->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::put('current-site', CurrentSiteController::class)->name('current-site');

        Route::get('cameras', [CameraWallController::class, 'index'])->name('cameras.index');
        Route::get('cameras/{camera}', [CameraWallController::class, 'show'])->name('cameras.show');
        Route::get('cameras/{camera}/preview', [CameraWallController::class, 'preview'])->name('cameras.preview');
        Route::get('cameras/{camera}/zones', ZoneEditorController::class)->name('cameras.zones');

        Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
        Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
        Route::post('incidents/{incident}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');
        Route::get('incidents/{incident}/report', [ReportController::class, 'incident'])->name('reports.incident');
        Route::get('sites/{site}/report', [ReportController::class, 'site'])->name('reports.site');
        Route::post('incidents/{incident}/notes', [IncidentController::class, 'note'])->name('incidents.notes');
        Route::post('incidents/{incident}/camera-view', [IncidentController::class, 'fixCameraView'])->name('incidents.camera-view');

        Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
        Route::post('sites', [SiteController::class, 'store'])->name('sites.store');
        Route::post('sites/{site}/patrols', [PatrolController::class, 'store'])->name('patrols.store');
        Route::post('sites/{site}/edges', [EdgeDeviceController::class, 'store'])->name('edges.store');
        Route::delete('sites/{site}/edges/{edge}', [EdgeDeviceController::class, 'destroy'])->name('edges.destroy');
        Route::get('ai/shadow', [ShadowController::class, 'index'])->name('shadow.index');
        Route::post('ai/shadow/detections/{detectionEvent}/label', [ShadowController::class, 'label'])->name('shadow.label');
        Route::get('team', [TeamController::class, 'index'])->name('team.index');
        Route::post('team', [TeamController::class, 'store'])->name('team.store');
        Route::patch('team/{member}', [TeamController::class, 'update'])->name('team.update');
        Route::delete('team/{member}', [TeamController::class, 'destroy'])->name('team.destroy');
        Route::get('clients', [ClientController::class, 'index'])->name('clients.index');
        Route::post('clients', [ClientController::class, 'store'])->name('clients.store');
        Route::patch('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::delete('clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
        Route::post('clients/{client}/admins', [ClientController::class, 'invite'])->name('clients.admins.store');
        Route::delete('clients/{client}/admins/{user}', [ClientController::class, 'removeAdmin'])->name('clients.admins.destroy');
        Route::patch('sites/{site}/client', [ClientController::class, 'assignSite'])->name('sites.client');
        Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');
        Route::patch('sites/{site}', [SiteConfigurationController::class, 'updateSite'])->name('sites.update');
        Route::get('sites/{site}/setup', [SiteSetupController::class, 'show'])->name('sites.setup');
        Route::post('sites/{site}/test-mode', [SiteSetupController::class, 'startTest'])->name('sites.test-mode');
        Route::delete('sites/{site}/test-mode', [SiteSetupController::class, 'stopTest'])->name('sites.test-mode.stop');
        Route::patch('sites/{site}/details', [SiteController::class, 'updateDetails'])->name('sites.details');
        Route::delete('sites/{site}', [SiteController::class, 'destroy'])->name('sites.destroy');
        Route::post('sites/{site}/residents', [ResidentAdminController::class, 'store'])->name('residents.store');
        Route::post('sites/{site}/residents/notify', [ResidentAdminController::class, 'notify'])->name('residents.notify');
        Route::patch('sites/{site}/residents/{resident}', [ResidentAdminController::class, 'update'])->name('residents.update');
        Route::delete('sites/{site}/residents/{resident}', [ResidentAdminController::class, 'destroy'])->name('residents.destroy');
        Route::patch('sites/{site}/perimeter', [SiteConfigurationController::class, 'updatePerimeter'])->name('sites.perimeter');
        Route::patch('cameras/{camera}', [SiteConfigurationController::class, 'updateCamera'])->name('cameras.update');
        Route::delete('cameras/{camera}', [SiteConfigurationController::class, 'destroyCamera'])->name('cameras.destroy');
        Route::patch('cameras/{camera}/placement', [SiteConfigurationController::class, 'updatePlacement'])->name('cameras.placement');
        Route::post('cameras/{camera}/zones', [SiteConfigurationController::class, 'storeZone'])->name('zones.store');
        Route::patch('zones/{zone}', [SiteConfigurationController::class, 'updateZone'])->name('zones.update');
        Route::patch('zones/{zone}/autonomy', [SiteConfigurationController::class, 'updateAutonomy'])->name('zones.autonomy');
        Route::delete('zones/{zone}', [SiteConfigurationController::class, 'destroyZone'])->name('zones.destroy');
        Route::post('zones/{zone}/schedules', [SiteConfigurationController::class, 'storeSchedule'])->name('schedules.store');
        Route::delete('schedules/{schedule}', [SiteConfigurationController::class, 'destroySchedule'])->name('schedules.destroy');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
