<?php

use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\Incident;
use App\Models\MediaView;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake(config('seguria.media_disk'));
    $this->site = Site::factory()->create();
    $this->camera = Camera::factory()->create(['site_id' => $this->site->id]);
    $this->incident = Incident::factory()->create(['site_id' => $this->site->id, 'camera_id' => $this->camera->id, 'snapshot_path' => 'x/snap.jpg']);
    Storage::disk(config('seguria.media_disk'))->put('x/snap.jpg', 'jpg');
    $this->operator = User::factory()->create(['role' => UserRole::Operator, 'organization_id' => $this->site->organization_id]);
});

it('records who opened an incident image, once per few minutes', function () {
    $url = URL::temporarySignedRoute('incidents.media', now()->addHour(), ['incident' => $this->incident->id, 'kind' => 'snapshot']);

    $this->actingAs($this->operator)->get($url)->assertOk();
    $this->actingAs($this->operator)->get($url)->assertOk();

    $view = MediaView::sole();
    expect($view->user_id)->toBe($this->operator->id)
        ->and($view->site_id)->toBe($this->site->id)
        ->and($view->kind)->toBe('incident')
        ->and($view->subject)->toContain("Incidente {$this->incident->id}");
});

it('also records a signed link opened without a session', function () {
    $this->get(URL::temporarySignedRoute('incidents.media', now()->addHour(), ['incident' => $this->incident->id, 'kind' => 'snapshot']))->assertOk();

    expect(MediaView::sole()->user_id)->toBeNull();
});

it('shows the log to the site managers in the settings tab only', function () {
    MediaView::create(['site_id' => $this->site->id, 'user_id' => $this->operator->id, 'kind' => 'live', 'subject' => 'Cámara Hall', 'viewed_at' => now()]);
    $admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $this->site->organization_id]);
    $guard = User::factory()->create(['role' => UserRole::Guard, 'organization_id' => $this->site->organization_id, 'site_id' => $this->site->id]);

    $this->actingAs($admin)->get("/sites/{$this->site->id}?tab=ajustes")
        ->assertInertia(fn ($page) => $page->has('mediaViews', 1)->where('mediaViews.0.who', $this->operator->name));
    $this->actingAs($guard)->get("/sites/{$this->site->id}?tab=ajustes")
        ->assertInertia(fn ($page) => $page->where('mediaViews', null));
});
