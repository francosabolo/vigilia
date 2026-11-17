<?php

use App\Http\Controllers\CameraDiscoveryController;
use App\Http\Controllers\Edge\CommandController;
use App\Http\Controllers\Edge\DetectionEventController;
use App\Http\Controllers\Edge\HeartbeatController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('edge')->middleware(['edge', 'throttle:1200,1'])->group(function () {
    Route::post('events', [DetectionEventController::class, 'store'])->name('edge.events.store');
    Route::post('heartbeat', HeartbeatController::class)->name('edge.heartbeat');
    Route::get('commands', [CommandController::class, 'index'])->name('edge.commands');
    Route::post('snapshots/{snapshotRequest}', [CommandController::class, 'storeSnapshot'])->name('edge.snapshots.store');
    Route::post('deterrence/{deterrenceCommand}', [CommandController::class, 'storeDeterrenceResult'])->name('edge.deterrence.store');
    Route::post('previews', [CommandController::class, 'storePreview'])->name('edge.previews.store');
    Route::post('discovered', [CameraDiscoveryController::class, 'discovered'])->name('edge.discovered');
    Route::post('camera-setups/{cameraSetup}', [CameraDiscoveryController::class, 'setupResult'])->name('edge.camera-setups.result');
    Route::post('health-frames', [CommandController::class, 'storeHealthFrame'])->name('edge.health-frames.store');
});

Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('webhooks.whatsapp.verify');
Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('webhooks.whatsapp');
