<?php

use App\Http\Controllers\CameraWallController;
use App\Http\Controllers\CurrentSiteController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IncidentController;
use App\Http\Controllers\IncidentMediaController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ResidentAdminController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\SiteConfigurationController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\ZoneEditorController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('signed')->group(function () {
    Route::get('media/incidents/{incident}/{kind}', IncidentMediaController::class)
        ->whereIn('kind', ['snapshot', 'clip'])
        ->name('incidents.media');
    Route::get('media/live-frames/{snapshotRequest}', [IncidentMediaController::class, 'liveFrame'])->name('snapshots.media');
});

Route::middleware('auth')->group(function () {
    Route::post('push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
    Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');

    Route::middleware('resident')->prefix('vecinos')->name('residents.')->group(function () {
        Route::get('/', [ResidentController::class, 'home'])->name('home');
        Route::post('panic', [ResidentController::class, 'panic'])->middleware('throttle:5,1')->name('panic');
        Route::post('report', [ResidentController::class, 'report'])->middleware('throttle:10,1')->name('report');
        Route::put('away', [ResidentController::class, 'updateAway'])->name('away');
    });

    Route::middleware('staff')->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::put('current-site', CurrentSiteController::class)->name('current-site');

        Route::get('cameras', [CameraWallController::class, 'index'])->name('cameras.index');
        Route::get('cameras/{camera}/preview', [CameraWallController::class, 'preview'])->name('cameras.preview');
        Route::get('cameras/{camera}/zones', ZoneEditorController::class)->name('cameras.zones');

        Route::get('incidents', [IncidentController::class, 'index'])->name('incidents.index');
        Route::get('incidents/{incident}', [IncidentController::class, 'show'])->name('incidents.show');
        Route::post('incidents/{incident}/acknowledge', [IncidentController::class, 'acknowledge'])->name('incidents.acknowledge');
        Route::post('incidents/{incident}/resolve', [IncidentController::class, 'resolve'])->name('incidents.resolve');
        Route::post('incidents/{incident}/notes', [IncidentController::class, 'note'])->name('incidents.notes');

        Route::get('sites', [SiteController::class, 'index'])->name('sites.index');
        Route::get('sites/{site}', [SiteController::class, 'show'])->name('sites.show');
        Route::patch('sites/{site}', [SiteConfigurationController::class, 'updateSite'])->name('sites.update');
        Route::post('sites/{site}/residents', [ResidentAdminController::class, 'store'])->name('residents.store');
        Route::delete('sites/{site}/residents/{resident}', [ResidentAdminController::class, 'destroy'])->name('residents.destroy');
        Route::patch('cameras/{camera}', [SiteConfigurationController::class, 'updateCamera'])->name('cameras.update');
        Route::post('cameras/{camera}/zones', [SiteConfigurationController::class, 'storeZone'])->name('zones.store');
        Route::patch('zones/{zone}', [SiteConfigurationController::class, 'updateZone'])->name('zones.update');
        Route::delete('zones/{zone}', [SiteConfigurationController::class, 'destroyZone'])->name('zones.destroy');
        Route::post('zones/{zone}/schedules', [SiteConfigurationController::class, 'storeSchedule'])->name('schedules.store');
        Route::delete('schedules/{schedule}', [SiteConfigurationController::class, 'destroySchedule'])->name('schedules.destroy');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
