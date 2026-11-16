<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('refuses to run the simulators in production', function (string $command) {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan($command)->assertFailed();
})->with(['seguria:simulate-operation', 'seguria:fake-edge', 'seguria:simulate']);

it('does not seed demo data in production unless forced', function () {
    app()->detectEnvironment(fn () => 'production');

    (new DatabaseSeeder)->run();
    expect(User::count())->toBe(0)->and(Organization::count())->toBe(0);

    config(['seguria.seed_demo' => true]);
    (new DatabaseSeeder)->run();
    expect(User::count())->toBeGreaterThan(0);
});
