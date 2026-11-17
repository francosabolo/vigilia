<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('refuses to run the simulators in production', function (string $command) {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan($command)->assertFailed();
})->with(['vigilia:simulate-operation', 'vigilia:fake-edge', 'vigilia:simulate']);

it('does not seed demo data in production unless forced', function () {
    app()->detectEnvironment(fn () => 'production');

    (new DatabaseSeeder)->run();
    expect(User::count())->toBe(0)->and(Organization::count())->toBe(0);

    config(['vigilia.seed_demo' => true]);
    (new DatabaseSeeder)->run();
    expect(User::count())->toBeGreaterThan(0);
});
