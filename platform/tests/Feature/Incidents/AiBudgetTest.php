<?php

use App\Enums\UserRole;
use App\Models\Site;
use App\Models\User;
use App\Notifications\AiBudgetReached;
use App\Services\Verification\AiBudget;
use Illuminate\Support\Facades\Notification;

it('allows verifications up to the daily cap per site and notifies the admins once', function () {
    Notification::fake();
    config(['vigilia.ai.daily_cap_per_site' => 2]);
    $site = Site::factory()->create();
    $other = Site::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin, 'organization_id' => $site->organization_id]);
    $budget = app(AiBudget::class);

    expect($budget->consume($site->id))->toBeTrue()
        ->and($budget->consume($site->id))->toBeTrue()
        ->and($budget->consume($site->id))->toBeFalse()
        ->and($budget->consume($site->id))->toBeFalse()
        ->and($budget->consume($other->id))->toBeTrue()
        ->and($budget->usedToday($site->id))->toBe(4);

    Notification::assertSentToTimes($admin, AiBudgetReached::class, 1);

    $this->travel(1)->days();
    expect($budget->consume($site->id))->toBeTrue();
});

it('has no cap when set to zero', function () {
    config(['vigilia.ai.daily_cap_per_site' => 0]);

    expect(collect(range(1, 5))->every(fn () => app(AiBudget::class)->consume(1)))->toBeTrue();
});
