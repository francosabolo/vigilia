<?php

use App\Models\DetectionEvent;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;

it('simulates operation through the real pipeline and reports what it caught', function () {
    Organization::factory()->create(['slug' => 'seguria']);

    $this->artisan('seguria:simulate-operation', ['--sites' => 1, '--cameras' => '6-6', '--days' => 2, '--recall' => 1, '--false-pass' => 0])
        ->expectsOutputToContain('Resultado de la simulación')
        ->assertSuccessful();

    $site = Site::where('is_simulated', true)->sole();
    expect($site->cameras()->count())->toBeGreaterThan(0)
        ->and($site->perimeter)->toHaveCount(4)
        ->and(User::where('site_id', $site->id)->where('role', 'resident')->count())->toBeGreaterThan(0)
        ->and(DetectionEvent::count())->toBeGreaterThan(0);

    $this->artisan('seguria:simulate-operation', ['--reset' => true, '--sites' => 0, '--days' => 0])->assertSuccessful();
    expect(Site::where('is_simulated', true)->count())->toBe(0);
});
