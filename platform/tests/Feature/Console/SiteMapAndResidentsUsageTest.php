<?php

use App\Enums\UserRole;
use App\Models\Camera;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->site = Site::factory()->create();
    $this->siteAdmin = User::factory()->create(['role' => UserRole::SiteAdmin, 'site_id' => $this->site->id]);
    $this->guard = User::factory()->create(['role' => UserRole::Guard, 'site_id' => $this->site->id]);
});

it('saves the perimeter and where each camera is installed', function () {
    $camera = Camera::factory()->create(['site_id' => $this->site->id]);
    $perimeter = [[-34.457, -58.916], [-34.457, -58.912], [-34.460, -58.912]];

    $this->actingAs($this->siteAdmin)->patch("/sites/{$this->site->id}/perimeter", ['perimeter' => $perimeter, 'center' => [-34.4587, -58.9142]])->assertRedirect();
    $this->actingAs($this->siteAdmin)->patch("/cameras/{$camera->id}/placement", ['lat' => -34.4577, 'lng' => -58.916, 'heading' => 90, 'fov' => 90, 'range_m' => 60])->assertRedirect();

    expect($this->site->fresh()->perimeter)->toBe($perimeter)
        ->and($camera->fresh()->only('heading', 'fov', 'range_m'))->toBe(['heading' => 90, 'fov' => 90, 'range_m' => 60]);

    $this->actingAs($this->guard)->patch("/cameras/{$camera->id}/placement", ['lat' => 0, 'lng' => 0, 'heading' => 0, 'fov' => 90, 'range_m' => 40])->assertForbidden();
    $this->actingAs($this->siteAdmin)->patch("/sites/{$this->site->id}/perimeter", ['perimeter' => [[1, 1], [2, 2]]])->assertStatus(422);
});

it('shows the map data and the coverage tab', function () {
    Camera::factory()->create(['site_id' => $this->site->id, 'lat' => -34.4577, 'lng' => -58.916, 'heading' => 90]);

    $this->actingAs($this->guard)->get("/sites/{$this->site->id}?tab=cobertura")->assertInertia(fn (Assert $page) => $page
        ->component('sites/show')
        ->where('tab', 'cobertura')
        ->where('site.cameras.0.position', [-34.4577, -58.916])
        ->where('site.cameras.0.heading', 90));

    // Settings are for managers only.
    $this->actingAs($this->guard)->get("/sites/{$this->site->id}?tab=ajustes")->assertInertia(fn (Assert $page) => $page->where('tab', 'resumen'));
});

it('tells the administration which residents use the app and on how many devices', function () {
    $active = User::factory()->create(['role' => UserRole::Resident, 'site_id' => $this->site->id, 'lot' => '1']);
    $never = User::factory()->create(['role' => UserRole::Resident, 'site_id' => $this->site->id, 'lot' => '2']);
    foreach (['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)', 'Mozilla/5.0 (Macintosh; Intel Mac OS X)'] as $i => $agent) {
        DB::table('sessions')->insert(['id' => "s{$i}", 'user_id' => $active->id, 'user_agent' => $agent, 'payload' => '', 'last_activity' => now()->timestamp, 'ip_address' => null]);
    }
    $active->updatePushSubscription('https://push.example.com/a', 'k', 't');

    $this->post('/login', ['email' => $active->email, 'password' => 'password']);
    expect($active->fresh()->last_login_at)->not->toBeNull();
    auth()->logout();

    $this->actingAs($this->siteAdmin)->get("/sites/{$this->site->id}?tab=vecinos")->assertInertia(fn (Assert $page) => $page
        ->where('residents.0.lot', '1')
        ->where('residents.0.active_sessions', 2)
        ->where('residents.0.devices', ['iPhone', 'Mac'])
        ->where('residents.0.push_devices', 1)
        ->where('residents.1.lot', '2')
        ->where('residents.1.last_login_at', null)
        ->where('residents.1.active_sessions', 0));
});

it('opens one camera live, not the whole wall', function () {
    $camera = Camera::factory()->create(['site_id' => $this->site->id, 'name' => 'Portón']);
    Camera::factory()->create(['site_id' => $this->site->id]);

    $this->actingAs($this->guard)->get("/cameras/{$camera->id}")->assertInertia(fn (Assert $page) => $page
        ->component('cameras/show')
        ->where('camera.name', 'Portón')
        ->has('siblings', 2));

    $stranger = User::factory()->create(['role' => UserRole::Guard, 'site_id' => Site::factory()->create()->id]);
    $this->actingAs($stranger)->get("/cameras/{$camera->id}")->assertForbidden();
});
